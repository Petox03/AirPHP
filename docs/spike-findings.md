# Spike findings — PHP sockets inside NativePHP's embedded runtime

**Phase 0 question:** nobody had documented a PHP socket server listening inside
NativePHP Mobile's embedded runtime. This spike answers it on a real device.

**Device:** POCO M5 (22071219CG), MIUI 1.0.12 / Android 14 (API 34), MediaTek.
MIUI is one of the most aggressive OEMs at killing background processes — which
makes the N5 result more meaningful. Wi-Fi: home LAN, phone `192.168.1.65`,
laptop `192.168.1.70`.

## The answer

`stream_socket_server` + `stream_select`, running inside an `AsyncTask`
(full Laravel boot on a separate PHP interpreter, `->timeout(0)`), serves the
LAN. No extensions needed — core streams are enough.

## Test matrix — all passed, `app-release.apk` (75.8 MB), 2026-11-21

| Level | Test | Result | Notes |
|---|---|---|---|
| N0 | Network permissions + Wi-Fi IP | ✅ | `INTERNET` ships in the template; bind `0.0.0.0:8080` OK; IP via UDP connect to `8.8.8.8:53` (no traffic sent) |
| N1 | Reachable from laptop browser | ✅ | `GET /hello` → `hello`, ~8 ms LAN latency |
| N2 | Streaming upload to disk | ✅ | 100 MB in 6.1 s (~17 MB/s), **1 GB in 45.4 s (~23.6 MB/s)** via raw PUT, md5 identical, no OOM |
| N3 | Download with `Content-Length` | ✅ | 100 MB ~22 MB/s, 1 GB ~21.8 MB/s, md5 identical |
| N4 | Concurrency | ✅ | Two concurrent 100 MB uploads completed; `/status` answered in ~55–76 ms under load |
| N5 | Lifecycle | ✅ | ~60 s of pings with app backgrounded **and** screen off — socket stayed alive |
| N6 | Stop / restart | ✅ | Clean stop via flag file; rebind OK after app reopen |

Post-matrix additions, verified on-device 2026-11-23:

- `multipart/form-data` uploads (`curl -F`, plain HTML `<form>`) — parsed in
  streaming with a sliding-window boundary scanner
- `Expect: 100-continue` honored — curl doesn't stall
- Received media lands in `Download/AirPHP` **with no storage permission**
  (API 34) and is indexed into the gallery via MediaStore (`vipertecpro/photo-kit`)

## Notable findings

| ID | Finding |
|---|---|
| H1 | `endroid/qr-code 6.2.0` requires PHP `^8.5` but the Android runtime is 8.4.28 — `config.platform.php` in composer.json must pin the **target** platform, not the dev machine's |
| H5 | `native:package` bundles the whole project dir — stale APKs and test uploads inflated the bundle to 147 MB until excluded via `cleanup_exclude_files` |
| H7 | **Zombie state:** killing the app leaves a stale "running" heartbeat in `status.json`. Fix: the UI probes `127.0.0.1:8080` instead of trusting the file |
| H8 | **The gallery doesn't index `fwrite`:** files PHP writes to `Download/` are invisible to the gallery until registered in MediaStore — which requires native code, so publishing is queued to the main lane (`gallery.queue`), the lane with the native bridge |
| H9 | **Missing multipart = silent corruption:** a classic `<form>` post would have been stored boundary-headers-and-all as file content, with a 200. Caught and fixed before it bit |

## Conclusion: GO — pure PHP for the server

~23 MB/s sustained, streaming verified by hash, concurrent clients, and the
socket survives a minute in background. The Kotlin plugin fallback (Plan B) is
discarded. What's still open for the real MVP: per-upload on-device
confirmation, upload size limits, an identity-proving liveness probe, and a
foreground service if background serving is ever promised.
