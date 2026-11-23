<?php

namespace App\NativeComponents;

use App\Spike\LanServerTask;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
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
