<?php

namespace App\Spike;

use Native\Mobile\AsyncTask;

/**
 * Envuelve el bucle del servidor en la lane de background de NativePHP.
 * Corre indefinidamente (el dispatch lleva ->timeout(0)) hasta que la
 * pantalla escriba la bandera {dir}/server.stop o el bucle falle.
 */
final class LanServerTask extends AsyncTask
{
    /** @return array{requests: int, bytes_received: int, bytes_sent: int, seconds: float} */
    public function handle(string $dir, int $port, ?string $uploadsDir = null): array
    {
        return (new LanServer($port, $dir, $uploadsDir))->run();
    }
}
