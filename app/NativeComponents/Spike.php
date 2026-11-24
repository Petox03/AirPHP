<?php

namespace App\NativeComponents;

use App\Spike\LanServerTask;
use App\Spike\SqliteWriteTask;
use App\Spike\UdpListenTask;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Share;
use NativePHP\Clipboard\Facades\Clipboard;
use Smronju\NativephpDocumentPicker\Events\DocumentPicked;
use Smronju\NativephpDocumentPicker\Facades\DocumentPicker;
use Vipertecpro\PhotoKit\Events\PhotoSaved;
use Vipertecpro\PhotoKit\Events\PhotoSaveFailed;
use Vipertecpro\PhotoKit\Facades\PhotoKit;

class Spike extends NativeComponent
{
    public string $status = 'stopped';

    public string $address = '';

    public string $error = '';

    public int $requests = 0;

    public string $bytesReceived = '0 B';

    public string $log = '';

    public string $uploadPath = '';

    public bool $showSensitive = true;

    public string $testRows = '100';

    public string $testSleepMs = '0';

    public string $qrPath = '';

    /** @var array<array<int>> */
    public array $qrMatrix = [];

    public function mount(): void
    {
        File::ensureDirectoryExists($this->dir());
        $this->uploadPath = $this->uploadsDir();
        $this->reconcileState();

        $ip = self::lanIp();
        $this->address = $ip === '' ? '(no local IP)' : "http://{$ip}:{$this->port()}";

        $this->refresh();
    }

    public function startServer(): void
    {
        if ($this->status === 'starting…' || $this->serverIsAlive()) {
            return;
        }

        @unlink($this->dir().'/server.stop');
        File::put($this->dir().'/server.log', '');
        $this->error = '';
        $this->status = 'starting…';

        LanServerTask::dispatch($this->dir(), $this->port(), $this->uploadPath)
            ->timeout(0)
            ->finished(fn () => $this->status = 'stopped')
            ->failed(fn (\Throwable $e) => $this->error = $e->getMessage());
    }

    public function stopServer(): void
    {
        if (! $this->serverIsAlive()) {
            // Proceso muerto (app asesinada): la bandera no la lee nadie,
            // el estado mentiroso se limpia aquí mismo.
            @unlink($this->dir().'/status.json');
            $this->status = 'stopped';

            return;
        }

        touch($this->dir().'/server.stop');
    }

    public function toggleSensitive(): void
    {
        $this->showSensitive = ! $this->showSensitive;
    }

    /**
     * Prueba temporal pre-Fase 1: dos procesos (lane UI + AsyncTask) escriben
     * la misma SQLite en paralelo. Responde: ¿journal_mode/WAL disponible en
     * el runtime embebido? ¿"database is locked" con escritores concurrentes?
     */
    public function sqliteTest(): void
    {
        try {
            $before = (string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '?');
            DB::statement('PRAGMA journal_mode=WAL');
            $after = (string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '?');
            $busy = (string) (DB::selectOne('PRAGMA busy_timeout')->busy_timeout ?? '?');
            $sync = (string) (DB::selectOne('PRAGMA synchronous')->synchronous ?? '?');
            $this->logLine("sqlite: journal_mode {$before} -> {$after} (WAL attempt) | busy_timeout={$busy} synchronous={$sync}");
        } catch (\Throwable $e) {
            $this->logLine('sqlite pragma failed: '.$e->getMessage());

            return;
        }

        DB::statement('DROP TABLE IF EXISTS spike_sqlite_test');
        DB::statement('CREATE TABLE spike_sqlite_test (id INTEGER PRIMARY KEY AUTOINCREMENT, lane TEXT, seq INTEGER, written_at REAL)');

        $rows = max(1, min(5000, (int) $this->testRows));
        $sleepUs = max(0, min(1000, (int) $this->testSleepMs)) * 1000;
        $this->logLine("sqlite test: {$rows} rows x {$this->testSleepMs}ms per lane");

        SqliteWriteTask::dispatch($this->dir(), $rows, $sleepUs)
            ->timeout(30 + (int) ($rows * $sleepUs / 1_000_000))
            ->finished(fn () => $this->sqliteSummary())
            ->failed(fn (\Throwable $e) => $this->logLine('sqlite task failed: '.$e->getMessage()));

        $written = 0;
        $errors = 0;
        for ($i = 1; $i <= $rows; $i++) {
            try {
                DB::table('spike_sqlite_test')->insert([
                    'lane' => 'ui',
                    'seq' => $i,
                    'written_at' => microtime(true),
                ]);
                $written++;
            } catch (\Throwable $e) {
                $errors++;
                if ($errors === 1) {
                    $this->logLine('ui first write error: '.$e->getMessage());
                }
            }
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $this->logLine("ui lane done: {$written}/{$rows} written, {$errors} errors");
    }

    /**
     * Limpieza de la instrumentación: tabla de prueba, archivos IPC/log del
     * spike y todo lo subido durante las pruebas (carpeta de uploads activa).
     */
    public function cleanTestData(): void
    {
        try {
            DB::statement('DROP TABLE IF EXISTS spike_sqlite_test');
            DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
            $this->logLine('clean: spike_sqlite_test dropped, wal checkpointed');
        } catch (\Throwable $e) {
            $this->logLine('clean db failed: '.$e->getMessage());
        }

        foreach (File::files($this->dir()) as $file) {
            @unlink($file->getPathname());
        }
        $this->logLine('clean: spike dir wiped');

        $uploads = $this->uploadsDir();
        $removed = 0;
        foreach (File::files($uploads) as $file) {
            if (@unlink($file->getPathname())) {
                $removed++;
            }
        }
        $this->logLine("clean: {$removed} file(s) removed from {$uploads}");
    }

    /**
     * Probes pre-Fase 1 (temporales): verifican en dispositivo lo que el mock
     * de Lovable promete — almacenamiento, QR, UDP broadcast, media picker
     * y share-sheet como workaround del clipboard ausente en core.
     */
    public function probeStorage(): void
    {
        foreach (['/storage/emulated/0', '/storage/emulated/0/Download', $this->uploadPath, storage_path()] as $p) {
            $free = @disk_free_space($p);
            $total = @disk_total_space($p);
            $this->logLine(sprintf(
                'storage %s: %s free / %s total',
                $p,
                $free === false ? '?' : self::humanBytes((int) $free),
                $total === false ? '?' : self::humanBytes((int) $total),
            ));
        }
    }

    public function probeQr(): void
    {
        $url = $this->address !== '' ? $this->address : 'http://127.0.0.1';

        // endroid/qr-code queda excluido del bundle (native:package recorta
        // vendor/endroid/*); bacon es PHP puro y sí llega al dispositivo.
        // La matriz 0/1 se dibuja como grid de columnas — cero imágenes.
        try {
            $matrix = Encoder::encode($url, ErrorCorrectionLevel::L())->getMatrix();
            $w = $matrix->getWidth();
            $h = $matrix->getHeight();
            $rows = [];
            for ($r = 0; $r < $h; $r++) {
                for ($c = 0; $c < $w; $c++) {
                    $rows[$r][] = $matrix->get($c, $r);
                }
            }
            $this->qrMatrix = $rows;
            $this->logLine("qr matrix: {$w}x{$h} modules — grid render");
        } catch (\Throwable $e) {
            $this->logLine('qr matrix failed: '.$this->errAt($e));
        }
    }

    private function errAt(\Throwable $e): string
    {
        return $e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine();
    }

    public function probeUdpSend(): void
    {
        $beacon = 'AirPHP:'.self::lanIp().':'.$this->port();
        $this->logLine('sockets_ext='.(function_exists('socket_import_stream') ? 'yes' : 'no'));

        // Unicast a la laptop = control; broadcast = lo que el discovery necesita.
        // sendto() en socket bound evita el connect() que exigía SO_BROADCAST prematuro.
        foreach (['192.168.1.70' => 'unicast-laptop', '192.168.1.255' => 'bcast-subnet', '255.255.255.255' => 'bcast-all'] as $target => $label) {
            $result = self::udpSendTo($beacon, $target, 9998);
            $this->logLine("udp {$label} ({$target}): {$result}");
        }
    }

    private static function udpSendTo(string $payload, string $ip, int $port): string
    {
        $sock = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);

        if ($sock === false) {
            return "bind-fail({$errstr})";
        }

        if (function_exists('socket_import_stream')) {
            $s = @socket_import_stream($sock);
            if ($s) {
                @socket_set_option($s, SOL_SOCKET, SO_BROADCAST, 1);
            }
        }

        error_clear_last();
        $n = @stream_socket_sendto($sock, $payload, 0, "{$ip}:{$port}");
        $err = $n === false ? (error_get_last()['message'] ?? '?') : '';
        fclose($sock);

        return $n === false ? "sendto-fail({$err})" : "sent {$n}B";
    }

    public function probeUdpListen(): void
    {
        UdpListenTask::dispatch($this->dir())
            ->timeout(75)
            ->finished(fn () => $this->logLine('udp listener finished'))
            ->failed(fn (\Throwable $e) => $this->logLine('udp listener failed: '.$e->getMessage()));

        $this->logLine('udp listener started (:9999, ~60s)');
    }

    public function probePicker(): void
    {
        try {
            DocumentPicker::pick();
            $this->logLine('document picker dispatched (any type)');
        } catch (\Throwable $e) {
            $this->logLine('picker threw: '.$e->getMessage());
        }
    }

    #[On(DocumentPicked::class)]
    public function onDocumentPicked(?string $path = null, ?string $name = null): void
    {
        if ($path === null) {
            $this->logLine('picker cancelled or failed (path=null)');

            return;
        }

        $this->logLine('picked: '.$path.' ('.$name.') '
            .(is_file($path) ? self::humanBytes((int) filesize($path)).' readable' : '[NOT readable]'));
    }

    public function probeClipboard(): void
    {
        try {
            $ok = Clipboard::writeText('AirPHP '.date('H:i:s'));
            $back = Clipboard::readText();
            $this->logLine('clipboard write='.($ok ? 'ok' : 'fail')." read='{$back}'");
        } catch (\Throwable $e) {
            $this->logLine('clipboard threw: '.$e->getMessage());
        }
    }

    /**
     * El log completo al portapapeles — para pegarlo donde sea sin andar
     * seleccionando texto en pantalla.
     */
    public function copyLog(): void
    {
        $log = (string) @file_get_contents($this->dir().'/server.log');

        if ($log === '') {
            $this->logLine('nothing to copy (log empty)');

            return;
        }

        try {
            $this->logLine('copy log: '.(Clipboard::writeText($log) ? 'copied' : 'write failed'));
        } catch (\Throwable $e) {
            $this->logLine('copy log threw: '.$e->getMessage());
        }
    }

    public function probeShare(): void
    {
        $this->logLine('share: nativephp_call='.(function_exists('nativephp_call') ? 'yes' : 'NO'));

        try {
            Share::url('AirPHP server', 'Server running at '.$this->address, $this->address);
            $this->logLine('share dispatched');
        } catch (\Throwable $e) {
            $this->logLine('share threw: '.$e->getMessage());
        }
    }

    /**
     * Copia el log a la carpeta pública de uploads para abrirlo/compartirlo
     * desde el gestor de archivos. Además GET /log ya lo expone por HTTP.
     */
    public function exportLog(): void
    {
        $dest = $this->uploadsDir().'/airphp-log-'.date('Ymd-His').'.txt';

        if (@copy($this->dir().'/server.log', $dest)) {
            $this->logLine('log exported: '.$dest);
        } else {
            $this->logLine('log export failed');
        }
    }

    private function sqliteSummary(): void
    {
        try {
            $counts = DB::table('spike_sqlite_test')
                ->selectRaw('lane, COUNT(*) as c')
                ->groupBy('lane')
                ->pluck('c', 'lane')
                ->all();
            $this->logLine('sqlite totals: '.json_encode($counts));
        } catch (\Throwable $e) {
            $this->logLine('sqlite summary failed: '.$e->getMessage());
        }
    }

    #[Poll(1000)]
    public function refresh(): void
    {
        $statusFile = $this->dir().'/status.json';

        if (is_file($statusFile)) {
            $status = json_decode((string) file_get_contents($statusFile), true);

            if (is_array($status)) {
                $state = (string) ($status['state'] ?? $this->status);

                // El heartbeat se escribe ~cada segundo; un "activo" con más
                // de 4s de antigüedad es un proceso muerto (app asesinada).
                if ($state === 'active' && time() - (int) @filemtime($statusFile) > 4) {
                    $state = 'stopped';
                }

                $this->status = $state;
                $this->requests = (int) ($status['requests'] ?? 0);
                $this->bytesReceived = self::humanBytes((int) ($status['bytes_received'] ?? 0));

                if (! empty($status['error'])) {
                    $this->error = (string) $status['error'];
                }
            }
        }

        $logFile = $this->dir().'/server.log';
        if (is_file($logFile)) {
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($logFile))));
            $this->log = implode("\n", array_slice($lines, -30));
        }

        $this->drainGalleryQueue();
    }

    /**
     * PhotoKit confirmó la publicación en MediaStore (álbum "AirPHP").
     */
    #[On(PhotoSaved::class)]
    public function onPhotoSaved(string $path): void
    {
        $this->logLine('in gallery: '.basename($path));
    }

    #[On(PhotoSaveFailed::class)]
    public function onPhotoSaveFailed(string $path, ?string $reason = null, ?string $message = null): void
    {
        $this->logLine('gallery rejected '.basename($path).': '.($message ?? $reason ?? 'error'));
    }

    public function render(): View
    {
        return view('native.spike');
    }

    /**
     * Al abrir la pantalla: si el último heartbeat decía "activo" pero ya no
     * hay nadie escuchando, el proceso murió con la app y el archivo miente.
     */
    private function reconcileState(): void
    {
        $statusFile = $this->dir().'/status.json';
        $status = is_file($statusFile)
            ? json_decode((string) @file_get_contents($statusFile), true)
            : null;

        if (! is_array($status) || ($status['state'] ?? '') !== 'active') {
            return;
        }

        if ($this->serverIsAlive()) {
            return;
        }

        @unlink($this->dir().'/server.stop');
        @unlink($statusFile);
        $this->status = 'stopped';
    }

    /**
     * Publica en la galería (álbum "AirPHP") las imágenes/videos que el server
     * terminó de recibir. Corre aquí y no en el AsyncTask porque el bridge
     * nativo solo existe en esta lane. Solo media: el resto queda en la
     * carpeta sin indexarse.
     */
    private function drainGalleryQueue(): void
    {
        $queue = $this->dir().'/gallery.queue';

        // rename-atómico: lo que el server encole después va a un archivo nuevo.
        if (! is_file($queue) || ! @rename($queue, $queue.'.processing')) {
            return;
        }

        $paths = array_filter(array_map('trim', explode("\n", (string) file_get_contents($queue.'.processing'))));
        @unlink($queue.'.processing');

        foreach ($paths as $path) {
            if (! is_file($path) || PhotoKit::detectType($path) === 'unknown') {
                continue;
            }

            try {
                PhotoKit::save($path, ['album' => 'AirPHP', 'id' => basename($path)]);
            } catch (\Throwable $e) {
                $this->logLine('could not publish '.basename($path).' — '.$e->getMessage());
            }
        }
    }

    private function logLine(string $message): void
    {
        @file_put_contents($this->dir().'/server.log', '['.date('H:i:s')."] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private function serverIsAlive(): bool
    {
        $conn = @stream_socket_client("tcp://127.0.0.1:{$this->port()}", $errno, $errstr, 0.4);

        if ($conn === false) {
            return false;
        }

        fclose($conn);

        return true;
    }

    private function dir(): string
    {
        return storage_path('app/spike');
    }

    /**
     * Carpeta visible para el usuario donde caen los archivos recibidos.
     * Orden de preferencia: carpeta pública (requiere "Acceso a todos los
     * archivos" concedido en Ajustes), luego el Android/data de la app
     * (siempre escribible, navegable desde Files), al final el interno.
     */
    private function uploadsDir(): string
    {
        $appId = (string) config('nativephp.app_id', 'com.example.app');

        // Las rutas públicas solo existen en el runtime Android (Linux);
        // en Windows una ruta /storage/... se resolvería contra C:\.
        if (PHP_OS_FAMILY === 'Linux' && is_dir('/storage/emulated/0')) {
            foreach ([
                '/storage/emulated/0/AirPHP',
                '/storage/emulated/0/Download/AirPHP',
                "/storage/emulated/0/Android/data/{$appId}/files/AirPHP",
            ] as $candidate) {
                if (self::ensureWritableDir($candidate)) {
                    return $candidate;
                }
            }
        }

        $internal = $this->dir().'/uploads';
        File::ensureDirectoryExists($internal);

        return $internal;
    }

    private static function ensureWritableDir(string $path): bool
    {
        if (! is_dir($path) && ! @mkdir($path, 0777, true)) {
            return false;
        }

        $probe = $path.'/.write-test';
        if (@file_put_contents($probe, 'x') === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }

    private function port(): int
    {
        return (int) env('SPIKE_PORT', 8080);
    }

    private static function lanIp(): string
    {
        // Truco UDP: "conectar" no envía tráfico pero resuelve la IP local
        // de la interfaz de salida sin depender de APIs nativas.
        $socket = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);

        if ($socket === false) {
            return '';
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return explode(':', $name)[0] ?? '';
    }

    private static function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 2).' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 2).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => $bytes.' B',
        };
    }
}
