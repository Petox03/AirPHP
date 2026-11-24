<?php

namespace App\Spike;

use Native\Mobile\AsyncTask;

/**
 * Instrumentación temporal (probe UDP, pre-Fase 1).
 * Escucha UDP en :9999 durante ~60s y loguea cada datagrama con su origen —
 * responde si el runtime embebido puede recibir broadcast en la LAN
 * (mitad del "descubrimiento tipo AirDrop").
 */
final class UdpListenTask extends AsyncTask
{
    public function handle(string $dir): array
    {
        $log = $dir.'/server.log';
        $say = function (string $msg) use ($log) {
            @file_put_contents($log, '['.date('H:i:s')."] [udp] {$msg}\n", FILE_APPEND | LOCK_EX);
        };

        $sock = @stream_socket_server('udp://0.0.0.0:9999', $errno, $errstr, STREAM_SERVER_BIND);
        if ($sock === false) {
            $say("bind failed: {$errstr} ({$errno})");

            return ['ok' => false, 'error' => $errstr];
        }

        $say('listening udp :9999 for ~60s');
        $packets = 0;
        $deadline = time() + 60;

        while (time() < $deadline) {
            $read = [$sock];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) < 1) {
                continue;
            }
            $peer = '';
            $data = @stream_socket_recvfrom($sock, 4096, 0, $peer);
            if ($data !== false) {
                $packets++;
                $say('recv '.strlen($data).'B from '.$peer.': '.trim($data));
            }
        }

        fclose($sock);
        $say("listener done: {$packets} packet(s)");

        return ['ok' => true, 'packets' => $packets];
    }
}
