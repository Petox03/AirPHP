<?php

namespace App\Spike;

use RuntimeException;

/**
 * Servidor HTTP/1.1 mínimo sobre streams de PHP (stream_select), pensado para
 * correr dentro del runtime embebido de NativePHP en un AsyncTask.
 *
 * Protocolo deliberadamente mínimo para el spike:
 *   GET  /                → página HTML mínima (subida con progreso)
 *   GET  /hello           → "hola" (prueba de alcance)
 *   GET  /status          → JSON con contadores
 *   GET  /log             → contenido de server.log en texto plano (diagnóstico remoto)
 *   GET  /download/{name} → sirve un archivo de uploads/ con Content-Length
 *   PUT|POST /upload      → cuerpo crudo o multipart/form-data (primera parte
 *                           con filename) → streaming a disco
 *
 * Comunicación con la UI solo vía filesystem (compartido entre intérpretes):
 *   {dir}/server.stop  → bandera de parada (la pone la pantalla)
 *   {dir}/server.log   → log legible
 *   {dir}/status.json  → heartbeat con estado y contadores
 *   {uploadsDir}/      → archivos recibidos (puede vivir fuera de {dir},
 *                        p. ej. en una carpeta pública del teléfono)
 */
final class LanServer
{
    private const CHUNK = 65536;

    private const HEADER_LIMIT = 16384;

    /** @var array<int, resource> */
    private array $clients = [];

    /** @var array<int, array{state: string, buffer: string, out: string, file: resource|null, upload: string|null, remaining: int, upload_started: float, peer: string, mp_boundary: string}> */
    private array $state = [];

    private int $requests = 0;

    private int $bytesReceived = 0;

    private int $bytesSent = 0;

    private float $startedAt = 0.0;

    private float $lastHeartbeat = 0.0;

    private readonly string $uploadsDir;

    public function __construct(
        private readonly int $port,
        private readonly string $dir,
        ?string $uploadsDir = null,
    ) {
        $this->uploadsDir = $uploadsDir ?? $dir.'/uploads';
    }

    /**
     * Corre el bucle hasta que exista {dir}/server.stop. Devuelve estadísticas.
     */
    public function run(): array
    {
        $this->startedAt = microtime(true);

        $server = @stream_socket_server("tcp://0.0.0.0:{$this->port}", $errno, $error);
        if ($server === false) {
            $this->writeStatus('error', "bind failed: {$error} ({$errno})");
            throw new RuntimeException("Could not bind tcp://0.0.0.0:{$this->port}: {$error} ({$errno})");
        }

        stream_set_blocking($server, false);
        @mkdir($this->uploadsDir, 0777, true);
        $this->log("listening on 0.0.0.0:{$this->port} — uploads: {$this->uploadsDir}");
        $this->writeStatus('active');

        try {
            $this->loop($server);
        } finally {
            foreach ($this->clients as $conn) {
                @fclose($conn);
            }
            $this->clients = [];
            $this->state = [];
            fclose($server);
        }

        $this->log('stopped');
        $this->writeStatus('stopped');

        return [
            'requests' => $this->requests,
            'bytes_received' => $this->bytesReceived,
            'bytes_sent' => $this->bytesSent,
            'seconds' => round(microtime(true) - $this->startedAt, 2),
        ];
    }

    private function loop($server): void
    {
        $stopFlag = $this->dir.'/server.stop';

        while (! file_exists($stopFlag)) {
            $read = [$server];
            $write = [];
            $except = null;

            foreach ($this->clients as $id => $conn) {
                $st = $this->state[$id]['state'];
                if ($st === 'headers' || $st === 'body' || str_starts_with($st, 'mp-')) {
                    $read[] = $conn;
                } else {
                    $write[] = $conn;
                }
            }

            if (@stream_select($read, $write, $except, 0, 500000) === false) {
                break;
            }

            foreach ($read as $stream) {
                if ($stream === $server) {
                    while (($conn = @stream_socket_accept($server, 0)) !== false) {
                        $this->accept($conn);
                    }

                    continue;
                }
                $this->onReadable((int) $stream);
            }

            foreach ($write as $stream) {
                $this->onWritable((int) $stream);
            }

            $now = microtime(true);
            if ($now - $this->lastHeartbeat >= 1.0) {
                $this->lastHeartbeat = $now;
                $this->writeStatus('active');
            }
        }

        @unlink($stopFlag);
    }

    private function accept($conn): void
    {
        stream_set_blocking($conn, false);
        $id = (int) $conn;
        $peer = (string) @stream_socket_get_name($conn, true);

        $this->clients[$id] = $conn;
        $this->state[$id] = [
            'state' => 'headers',
            'buffer' => '',
            'out' => '',
            'file' => null,
            'upload' => null,
            'remaining' => 0,
            'upload_started' => 0.0,
            'peer' => $peer,
            'mp_boundary' => '',
        ];
    }

    private function onReadable(int $id): void
    {
        $conn = $this->clients[$id] ?? null;
        if ($conn === null) {
            return;
        }

        $chunk = @fread($conn, self::CHUNK);

        if ($chunk === false || ($chunk === '' && feof($conn))) {
            $this->drop($id, 'client disconnected');

            return;
        }

        if ($chunk === '') {
            return;
        }

        $st = &$this->state[$id];

        if ($st['state'] === 'headers') {
            $st['buffer'] .= $chunk;

            if (strlen($st['buffer']) > self::HEADER_LIMIT) {
                $this->respond($id, 400, 'headers too large');

                return;
            }

            $pos = strpos($st['buffer'], "\r\n\r\n");
            if ($pos !== false) {
                $head = substr($st['buffer'], 0, $pos);
                $bodyPrefix = substr($st['buffer'], $pos + 4);
                $st['buffer'] = '';
                $this->route($id, $head, $bodyPrefix);
            }

            return;
        }

        if ($st['state'] === 'body') {
            $this->ingestBody($id, $chunk);
        }

        if ($st['state'] === 'mp-head' || $st['state'] === 'mp-body') {
            $this->ingestMultipart($id, $chunk);
        }
    }

    private function route(int $id, string $head, string $bodyPrefix): void
    {
        $this->requests++;

        $lines = explode("\r\n", $head);
        $requestLine = explode(' ', (string) array_shift($lines));
        $method = strtoupper($requestLine[0] ?? '');
        [$path, $query] = array_pad(explode('?', $requestLine[1] ?? '/', 2), 2, '');
        parse_str($query, $params);

        $headers = [];
        foreach ($lines as $line) {
            $sep = strpos($line, ':');
            if ($sep !== false) {
                $headers[strtolower(trim(substr($line, 0, $sep)))] = trim(substr($line, $sep + 1));
            }
        }

        $this->log("{$method} {$path}");

        match (true) {
            $method === 'GET' && $path === '/' => $this->respond($id, 200, self::page(), 'text/html; charset=utf-8'),
            $method === 'GET' && $path === '/hello' => $this->respond($id, 200, "hello\n"),
            $method === 'GET' && $path === '/status' => $this->respond($id, 200, json_encode($this->stats()), 'application/json'),
            $method === 'GET' && $path === '/log' => $this->respond($id, 200, (string) @file_get_contents($this->dir.'/server.log'), 'text/plain; charset=utf-8'),
            $method === 'GET' && str_starts_with($path, '/download/') => $this->startDownload($id, $path),
            ($method === 'PUT' || $method === 'POST') && $path === '/upload' => $this->startUpload($id, $headers, $params, $bodyPrefix),
            default => $this->respond($id, 404, 'not found'),
        };
    }

    private function startUpload(int $id, array $headers, array $params, string $bodyPrefix): void
    {
        if (! isset($headers['content-length'])) {
            $this->respond($id, 411, 'Content-Length required');

            return;
        }

        $st = &$this->state[$id];
        $st['upload_started'] = microtime(true);

        // curl y otros clientes envían "Expect: 100-continue" y aguantan el
        // cuerpo hasta recibir el interim; responderlo directo evita su delay.
        if (strtolower((string) ($headers['expect'] ?? '')) === '100-continue') {
            @fwrite($this->clients[$id], "HTTP/1.1 100 Continue\r\n\r\n");
        }

        // multipart/form-data: el filename vive en los headers de la parte,
        // así que la máquina de estados arranca en 'mp-head' sin archivo abierto.
        $contentType = (string) ($headers['content-type'] ?? '');
        if (str_starts_with($contentType, 'multipart/form-data')) {
            if (! preg_match('/boundary="?([^";]+)"?/', $contentType, $m)) {
                $this->respond($id, 400, 'multipart sin boundary');

                return;
            }

            $st['state'] = 'mp-head';
            $st['mp_boundary'] = '--'.$m[1];
            $st['buffer'] = '';

            $this->log('multipart: esperando la primera parte con archivo');

            if ($bodyPrefix !== '') {
                $this->ingestMultipart($id, $bodyPrefix);
            }

            return;
        }

        $name = $params['name'] ?? $headers['x-filename'] ?? 'upload.bin';
        $name = basename(str_replace('\\', '/', rawurldecode((string) $name)));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'upload.bin';

        $fh = @fopen($this->uploadsDir.'/'.$name, 'wb');
        if ($fh === false) {
            $this->respond($id, 500, 'could not open destination');

            return;
        }

        $st['state'] = 'body';
        $st['file'] = $fh;
        $st['upload'] = $name;
        $st['remaining'] = (int) $headers['content-length'];

        $this->log("receiving {$name} ({$st['remaining']} bytes)");

        if ($bodyPrefix !== '') {
            $this->ingestBody($id, $bodyPrefix);
        }
    }

    private function ingestBody(int $id, string $data): void
    {
        $st = &$this->state[$id];

        $write = substr($data, 0, $st['remaining']);
        @fwrite($st['file'], $write);
        $this->bytesReceived += strlen($write);
        $st['remaining'] -= strlen($write);

        if ($st['remaining'] > 0) {
            return;
        }

        $this->finishUpload($id);
    }

    /**
     * Ingesta de multipart/form-data. Solo se toma la primera parte que traiga
     * `filename`: en 'mp-head' se acumula hasta cerrar los headers de la parte;
     * en 'mp-body' se vuelca a disco buscando el delimitador "\r\n--boundary"
     * (puede llegar partido entre chunks — se conserva cola deslizante).
     */
    private function ingestMultipart(int $id, string $data): void
    {
        $st = &$this->state[$id];
        $st['buffer'] .= $data;

        if ($st['state'] === 'mp-head') {
            $pos = strpos($st['buffer'], "\r\n\r\n");

            if ($pos === false) {
                if (strlen($st['buffer']) > self::HEADER_LIMIT) {
                    $this->respond($id, 400, 'multipart: headers too large');
                }

                return;
            }

            $head = substr($st['buffer'], 0, $pos);
            $rest = substr($st['buffer'], $pos + 4);

            if (! preg_match('/filename\*?="?([^";\r\n]+)"?/i', $head, $m) || trim($m[1]) === '') {
                $this->respond($id, 422, 'multipart: first part has no filename');

                return;
            }

            $name = basename(str_replace('\\', '/', rawurldecode(trim($m[1]))));
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'upload.bin';

            $fh = @fopen($this->uploadsDir.'/'.$name, 'wb');
            if ($fh === false) {
                $this->respond($id, 500, 'could not open destination');

                return;
            }

            $st['state'] = 'mp-body';
            $st['file'] = $fh;
            $st['upload'] = $name;
            $st['buffer'] = $rest;

            $this->log("multipart: receiving {$name}");
        }

        $delim = "\r\n".$st['mp_boundary'];
        $pos = strpos($st['buffer'], $delim);

        if ($pos === false) {
            // Sin delimitador aún: volcar todo salvo una cola de len-1 bytes,
            // por si el delimitador viene partido entre este chunk y el siguiente.
            $write = strlen($st['buffer']) - (strlen($delim) - 1);
            if ($write > 0) {
                @fwrite($st['file'], substr($st['buffer'], 0, $write));
                $this->bytesReceived += $write;
                $st['buffer'] = substr($st['buffer'], $write);
            }

            return;
        }

        @fwrite($st['file'], substr($st['buffer'], 0, $pos));
        $this->bytesReceived += $pos;
        $st['buffer'] = '';

        // Solo se toma la primera parte con archivo; el resto del multipart
        // se descarta al cerrar la conexión tras responder.
        $this->finishUpload($id);
    }

    private function finishUpload(int $id): void
    {
        $st = &$this->state[$id];
        $name = $st['upload'];
        $seconds = max(microtime(true) - $st['upload_started'], 0.001);
        $size = (int) @filesize($this->uploadsDir.'/'.$name);
        $mbps = round($size / 1048576 / $seconds, 2);

        fclose($st['file']);
        $st['file'] = null;

        $this->log("received {$name}: {$size} bytes in ".round($seconds, 2)."s ({$mbps} MB/s)");

        // Cola para la lane principal: la pantalla la drena y publica en la
        // galería (el bridge nativo no existe dentro de este AsyncTask).
        @file_put_contents($this->dir.'/gallery.queue', $this->uploadsDir.'/'.$name."\n", FILE_APPEND | LOCK_EX);

        $this->respond($id, 200, json_encode(['ok' => true, 'name' => $name, 'bytes' => $size]), 'application/json');
    }

    private function startDownload(int $id, string $path): void
    {
        $name = basename(rawurldecode(substr($path, strlen('/download/'))));
        $file = $this->uploadsDir.'/'.$name;

        if (! is_file($file)) {
            $this->respond($id, 404, 'not found');

            return;
        }

        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            $this->respond($id, 500, 'no se pudo leer');

            return;
        }

        $size = (int) filesize($file);
        $st = &$this->state[$id];
        $st['state'] = 'sendfile';
        $st['file'] = $fh;
        $st['out'] = "HTTP/1.1 200 OK\r\n"
            ."Content-Type: application/octet-stream\r\n"
            ."Content-Length: {$size}\r\n"
            ."Content-Disposition: attachment; filename=\"{$name}\"\r\n"
            ."Connection: close\r\n\r\n";

        $this->log("downloading {$name} ({$size} bytes)");
    }

    private function onWritable(int $id): void
    {
        $conn = $this->clients[$id] ?? null;
        if ($conn === null) {
            return;
        }

        $st = &$this->state[$id];

        if ($st['state'] === 'sendfile' && strlen($st['out']) < self::CHUNK && is_resource($st['file']) && ! feof($st['file'])) {
            $more = @fread($st['file'], self::CHUNK);
            if ($more !== false) {
                $st['out'] .= $more;
            }
        }

        if ($st['out'] !== '') {
            $written = @fwrite($conn, $st['out']);

            if ($written === false || ($written === 0 && feof($conn))) {
                $this->drop($id, 'write failed / client gone');

                return;
            }

            $st['out'] = substr($st['out'], $written);
            $this->bytesSent += $written;
        }

        $source = $st['file'] ?? null;
        $done = $st['out'] === ''
            && ($st['state'] === 'write' || ! is_resource($source) || feof($source));

        if ($done) {
            $this->drop($id, null);
        }
    }

    private function respond(int $id, int $code, string $body, string $contentType = 'text/plain; charset=utf-8'): void
    {
        $reason = [
            200 => 'OK',
            400 => 'Bad Request',
            404 => 'Not Found',
            411 => 'Length Required',
            422 => 'Unprocessable Content',
            500 => 'Internal Server Error',
        ][$code] ?? 'OK';

        $st = &$this->state[$id];
        $st['state'] = 'write';
        $st['out'] = "HTTP/1.1 {$code} {$reason}\r\n"
            ."Content-Type: {$contentType}\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n\r\n"
            .$body;
    }

    private function drop(int $id, ?string $reason): void
    {
        $st = $this->state[$id] ?? null;

        if ($st !== null && is_resource($st['file'])) {
            fclose($st['file']);

            if (($st['state'] === 'body' || $st['state'] === 'mp-body') && $st['upload'] !== null) {
                @unlink($this->uploadsDir.'/'.$st['upload']);
                $reason = "interrupted upload of {$st['upload']} — partial deleted";
            }
        }

        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]);
        }

        unset($this->clients[$id], $this->state[$id]);

        if ($reason !== null) {
            $this->log($reason);
        }
    }

    /**
     * @return array{requests: int, bytes_received: int, bytes_sent: int, clients: int}
     */
    private function stats(): array
    {
        return [
            'requests' => $this->requests,
            'bytes_received' => $this->bytesReceived,
            'bytes_sent' => $this->bytesSent,
            'clients' => count($this->clients),
        ];
    }

    private function writeStatus(string $state, ?string $error = null): void
    {
        @file_put_contents(
            $this->dir.'/status.json',
            json_encode($this->stats() + [
                'state' => $state,
                'error' => $error,
                'port' => $this->port,
                'seconds' => round(microtime(true) - $this->startedAt, 1),
            ]),
            LOCK_EX,
        );
    }

    private function log(string $message): void
    {
        @file_put_contents($this->dir.'/server.log', '['.date('H:i:s')."] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private static function page(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="es">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>AirPHP - Spike</title>
            <style>
            body{font-family:system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem}
            button{padding:.6rem 1.2rem;font-size:1rem}
            pre{background:#f0f0f0;padding:1rem;white-space:pre-wrap}
            </style>
            </head>
            <body>
            <h1>AirPHP - Spike</h1>
            <p>Pure PHP HTTP server running on the phone.</p>
            <p><input type="file" id="f"> <button onclick="enviar()">Upload</button></p>
            <form method="post" action="/upload" enctype="multipart/form-data">
            <p><input type="file" name="file"> <button type="submit">Upload (form)</button></p>
            </form>
            <pre id="p"></pre>
            <script>
            const p = document.getElementById('p');
            function enviar(){
              const f = document.getElementById('f').files[0];
              if(!f){ p.textContent = 'choose a file'; return; }
              const x = new XMLHttpRequest();
              x.open('PUT', '/upload');
              x.setRequestHeader('X-Filename', f.name);
              x.upload.onprogress = e => {
                if(e.lengthComputable) p.textContent = `uploading ${Math.round(e.loaded/e.total*100)}% — ${e.loaded}/${e.total} bytes`;
              };
              x.onload = () => p.textContent += '\nresponse: ' + x.responseText;
              x.onerror = () => p.textContent = 'network error';
              x.send(f);
            }
            </script>
            </body>
            </html>
            HTML;
    }
}
