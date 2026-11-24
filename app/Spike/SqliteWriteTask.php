<?php

namespace App\Spike;

use Illuminate\Support\Facades\DB;
use Native\Mobile\AsyncTask;

/**
 * Instrumentación temporal (prueba SQLite dual-lane, pre-Fase 1).
 * Corre en la lane del AsyncTask con Laravel booteado completo y escribe
 * $rows filas en la MISMA base que la lane de UI usa en paralelo.
 * Reporta vía server.log (mismo append con LOCK_EX que el spike).
 * Se elimina junto con el resto de la instrumentación.
 */
final class SqliteWriteTask extends AsyncTask
{
    /** @return array{written: int, errors: int, journal_mode: string} */
    public function handle(string $dir, int $rows, int $sleepUs): array
    {
        $log = $dir.'/server.log';
        $say = function (string $msg) use ($log) {
            @file_put_contents($log, '['.date('H:i:s')."] [task] {$msg}\n", FILE_APPEND | LOCK_EX);
        };

        $journalMode = '?';
        try {
            $journalMode = (string) (DB::selectOne('PRAGMA journal_mode')->journal_mode ?? '?');
            $say("task lane sees journal_mode={$journalMode}");
        } catch (\Throwable $e) {
            $say('pragma failed: '.$e->getMessage());
        }

        $written = 0;
        $errors = 0;
        for ($i = 1; $i <= $rows; $i++) {
            try {
                DB::table('spike_sqlite_test')->insert([
                    'lane' => 'task',
                    'seq' => $i,
                    'written_at' => microtime(true),
                ]);
                $written++;
            } catch (\Throwable $e) {
                $errors++;
                if ($errors === 1) {
                    $say('first write error: '.$e->getMessage());
                }
            }
            usleep($sleepUs);
        }

        $say("task lane done: {$written}/{$rows} written, {$errors} errors");

        return ['written' => $written, 'errors' => $errors, 'journal_mode' => $journalMode];
    }
}
