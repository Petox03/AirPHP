<?php

use App\Spike\LanServer;
use App\Spike\LanServerTask;
use Illuminate\Support\Facades\File;
use Native\Mobile\AsyncTask;
use Native\Mobile\Testing\Native;
use Vipertecpro\PhotoKit\Facades\PhotoKit;

it('renders the spike screen', function () {
    Native::visit('/spike')
        ->assertSee('AirPHP - Spike')
        ->assertSee('Start server')
        ->assertSee('Stop')
        ->assertSee('Status:')
        ->assertSee('Address:')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'start-server')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'stop-server');
});

it('writes the stop flag when stop is pressed and the server is alive', function () {
    $_ENV['SPIKE_PORT'] = $_SERVER['SPIKE_PORT'] = '18779';
    // La pantalla solo escribe la bandera si algo escucha en el puerto;
    // un listener real simula al server vivo.
    $listener = stream_socket_server('tcp://127.0.0.1:18779');

    $flag = storage_path('app/spike/server.stop');
    @unlink($flag);
    @rmdir($flag);

    try {
        Native::visit('/spike')->tap('stop-server');

        expect($flag)->toBeFile();
    } finally {
        fclose($listener);
        unset($_ENV['SPIKE_PORT'], $_SERVER['SPIKE_PORT']);
    }
});

it('dispatches the server task, which exits cleanly on the stop flag', function () {
    $dir = storage_path('app/spike');
    // La bandera como DIRECTORIO: file_exists() la ve, @unlink() no la quita,
    // así el task (inline bajo el fake) sale del bucle al instante.
    @rmdir($dir.'/server.stop');
    @unlink($dir.'/server.stop');
    @mkdir($dir.'/server.stop');
    @unlink($dir.'/status.json');
    $_ENV['SPIKE_PORT'] = $_SERVER['SPIKE_PORT'] = '18778';

    $fake = AsyncTask::fake();

    try {
        $component = Native::visit('/spike')->tap('start-server');

        $fake->assertDispatched(fn (array $d): bool => ($d['work']['task'] ?? null) === LanServerTask::class
            && ($d['work']['args'] ?? []) === [$dir, 18778, $dir.'/uploads']);

        $component
            ->assertSet('status', 'stopped')
            ->assertSet('error', '');
    } finally {
        @rmdir($dir.'/server.stop');
        unset($_ENV['SPIKE_PORT'], $_SERVER['SPIKE_PORT']);
        AsyncTask::clearFake();
    }
});

it('refuses a second dispatch while the server is running', function () {
    $dir = storage_path('app/spike');
    $_ENV['SPIKE_PORT'] = $_SERVER['SPIKE_PORT'] = '18779';
    file_put_contents($dir.'/status.json', json_encode(['state' => 'active']));
    $listener = stream_socket_server('tcp://127.0.0.1:18779');

    $fake = AsyncTask::fake();

    try {
        Native::visit('/spike')
            ->assertSet('status', 'active')
            ->tap('start-server');

        $fake->assertNotDispatched();
    } finally {
        fclose($listener);
        @unlink($dir.'/status.json');
        unset($_ENV['SPIKE_PORT'], $_SERVER['SPIKE_PORT']);
        AsyncTask::clearFake();
    }
});

it('cleans the zombie "activo" state left by a killed app', function () {
    $dir = storage_path('app/spike');
    $_ENV['SPIKE_PORT'] = $_SERVER['SPIKE_PORT'] = '18779';
    // Último heartbeat de un proceso muerto: decía "activo" y nadie escucha.
    file_put_contents($dir.'/status.json', json_encode(['state' => 'active', 'requests' => 9]));
    touch($dir.'/status.json', time() - 60);
    @unlink($dir.'/server.stop');
    @rmdir($dir.'/server.stop');

    $fake = AsyncTask::fake();

    try {
        $component = Native::visit('/spike');

        // mount() reconcilió: ni listener ni heartbeat fresco → detenido.
        $component->assertSet('status', 'stopped');
        expect(is_file($dir.'/status.json'))->toBeFalse();

        // Y "Iniciar" no se atora: vuelve a despachar (con la bandera como
        // directorio el task inline sale del bucle al instante).
        @mkdir($dir.'/server.stop');
        $component->tap('start-server');

        $fake->assertDispatched(fn (array $d): bool => ($d['work']['task'] ?? null) === LanServerTask::class);
        $component->assertSet('status', 'stopped');
    } finally {
        @rmdir($dir.'/server.stop');
        @unlink($dir.'/status.json');
        unset($_ENV['SPIKE_PORT'], $_SERVER['SPIKE_PORT']);
        AsyncTask::clearFake();
    }
});

it('drains the gallery queue, publishing only media files to PhotoKit', function () {
    $dir = storage_path('app/spike');
    File::ensureDirectoryExists($dir.'/uploads');

    $png = $dir.'/uploads/foto.png';
    $zip = $dir.'/uploads/doc.zip';
    file_put_contents($png, 'fake-png-bytes');
    file_put_contents($zip, 'PK03');
    // media + no-media + ruta borrada entre el encolado y el drenado.
    file_put_contents($dir.'/gallery.queue', "{$png}\n{$zip}\n{$dir}/uploads/gone.png\n");

    $mock = PhotoKit::partialMock();
    $mock->shouldReceive('save')->once()->with($png, ['album' => 'AirPHP', 'id' => 'foto.png']);

    try {
        Native::visit('/spike')->call('refresh');

        expect(is_file($dir.'/gallery.queue'))->toBeFalse();
    } finally {
        @unlink($dir.'/gallery.queue');
        @unlink($dir.'/gallery.queue.processing');
        @unlink($png);
        @unlink($zip);
    }
});

it('accepts multipart/form-data uploads without envelope corruption', function () {
    $dir = storage_path('app/spike-mp');
    File::ensureDirectoryExists($dir.'/uploads');
    $port = 18776;
    $autoload = str_replace('\\', '/', base_path('vendor/autoload.php'));
    $dirPhp = str_replace('\\', '/', $dir);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

    // Server real en subproceso: el parseo multipart solo se prueba de verdad
    // con bytes reales por socket, no con llamadas a métodos internos.
    $proc = proc_open(
        [PHP_BINARY, '-r', "require '{$autoload}'; (new App\\Spike\\LanServer({$port}, '{$dirPhp}'))->run();"],
        [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes
    );

    try {
        fclose($pipes[0]);

        $sock = null;
        for ($i = 0; $i < 50 && ! $sock; $i++) {
            $sock = @fsockopen('127.0.0.1', $port);
            usleep(100_000);
        }
        expect($sock)->not->toBeNull();

        // El ruido "\r\n--NOISE" dentro del archivo no debe truncarlo:
        // solo el delimitador exacto "\r\n--boundary" cierra la parte.
        $boundary = 'TESTBOUNDARYxYz123';
        $fileData = str_repeat('A', 100_000)."\r\n--NOISE\r\n".str_repeat('B', 50_000);
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"file\"; filename=\"foto.png\"\r\n"
            ."Content-Type: image/png\r\n\r\n"
            .$fileData
            ."\r\n--{$boundary}--\r\n";

        fwrite($sock, "POST /upload HTTP/1.1\r\nHost: x\r\n"
            ."Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            .'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n".$body);
        $response = stream_get_contents($sock);
        fclose($sock);

        expect($response)->toContain('200 OK')
            ->and(file_get_contents($dir.'/uploads/foto.png'))->toBe($fileData);
    } finally {
        @touch($dir.'/server.stop');
        if (is_resource($proc)) {
            proc_close($proc);
        }
        File::deleteDirectory($dir);
    }
});

it('binds, honours an existing stop flag and shuts down cleanly', function () {
    $dir = storage_path('app/spike-test');
    @mkdir($dir, 0777, true);
    touch($dir.'/server.stop');

    $result = (new LanServer(18777, $dir))->run();

    expect($result['requests'])->toBe(0)
        ->and(file_exists($dir.'/server.stop'))->toBeFalse()
        ->and(json_decode((string) file_get_contents($dir.'/status.json'), true)['state'])->toBe('stopped');
});
