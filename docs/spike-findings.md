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

## Round 2 — capability probes, 2026-11-24 (`app-release.apk` 79.7 MB)

Round 1 proved the server can exist. Round 2 proved what the product around it
can do — every capability probed on the same POCO M5:

| Capability | Result | How |
|---|---|---|
| Disk stats | ✅ | `disk_free_space` / `disk_total_space` on `/storage/emulated/0` — real values (30.3 GB free / 107 GB) |
| UDP discovery | ✅ | Broadcast **both ways**: beacon `AirPHP:IP:port` sent to `255.255.255.255` + subnet broadcast was received on the laptop; the listener also received broadcast datagrams from outside. AirDrop-style LAN discovery in pure PHP, no plugins |
| On-device QR | ✅ | `bacon/bacon-qr-code` `Encoder::encode() → getMatrix()` → rendered as a 25×25 grid of native UI elements. No images, no gd — Compose draws the QR |
| Share sheet | ✅ | `nativephp/mobile-share` (official, free) — `Share::url` / `Share::file` |
| Clipboard | ✅ | `nativephp/mobile-clipboard` (official) — writeText/readText roundtrip verified |
| File picker | ✅ | `smronju/nativephp-document-picker` — SAF `ACTION_OPEN_DOCUMENT`, any file type, copies to `cache/picked-documents/` and fires `DocumentPicked{path,name}` with a readable path |

### Round-2 findings

| ID | Finding |
|---|---|
| H10 | **`native:package` strips `vendor/endroid/*` from the bundle** — the package exists only for `native:jump`'s terminal QR. The autoloader knows the class (`ClassLoader.php` resolves it), the file isn't on the phone: instantiating `QrCode` dies with a PHP stream error, not a clean "class not found". `endroid/qr-code` cannot run on-device, period |
| H11 | **Facades without Kotlin handlers fail silently.** Core ships PHP facades (`Share::url`, `Camera::pickImages`) whose calls dispatch (`nativephp_call` exists, no exception) and vanish — the compiled bridge registers ~20 handlers (AsyncTask, Event, Device, Dialog, File, UI, Perf) and Share/Camera.PickMedia aren't among them. The handlers arrive in separate official plugins. **"The facade exists" ≠ "it works"** — every native facade now gets a device probe before entering a design |
| H12 | **`stream_socket_client` to a broadcast address dies with `EACCES` on Android** — `connect()` requires `SO_BROADCAST` set first. Working form: `stream_socket_server('udp://0.0.0.0:0', STREAM_SERVER_BIND)` + `socket_import_stream` + `socket_set_option(SO_BROADCAST)` + `stream_socket_sendto`. Windows doesn't enforce this — testing from a laptop gives a false positive |
| H13 | Third-party plugins are the ecosystem's real shape: the missing Kotlin handlers are deliberately split into small per-capability plugins, official and community alike. Budget for auditing them (R6-style) — a facade you can't see inside is one install away from working or from silently not |

Every capability the product mock promised now has a verified path on-device.
What's deliberately undecided stays undecided — this doc records what works,
not what ships.
