# Building the APK in Docker — no Android Studio, no SDK

The whole Android toolchain lives in `docker/android/` — an Ubuntu 24.04 image
mirroring the versions used by [`MrPunyapal/nativephp-build-action`](https://github.com/MrPunyapal/nativephp-build-action):
PHP 8.4, JDK 17, Node 22, Android SDK platform 36, build-tools 36.0.0,
NDK 27.0.12077973, CMake 3.22.1.

## Usage

```bash
docker build -t airphp-android docker/android

docker run --rm \
  -v "$PWD:/src:ro" \
  -v airphp-gradle:/root/.gradle \
  -v airphp-composer:/root/.composer \
  -v airphp-keys:/keys \
  -v "$PWD/native-builds:/out" \
  airphp-android bash /src/docker/android/package.sh
```

On Windows / Git Bash, prefix with `MSYS_NO_PATHCONV=1` so the `/src/...`
argument isn't rewritten to a Windows path.

Output: a signed `app-release.apk` in `native-builds/`. The keystore lives in
the persistent `airphp-keys` volume, so updates install over previous builds.

## Gotchas found the hard way

| # | Trap | Fix |
|---|---|---|
| H1 | `composer.lock` resolved on Windows (PHP 8.5) pulled packages requiring `^8.5`, but the embedded runtime is PHP **8.4.28** | `config.platform.php: "8.4.28"` in `composer.json` — resolve the lock against the target, not the dev machine |
| H2 | `native:install`/`native:run` read `/proc/version`, see the WSL2 kernel string under Docker Desktop, and abort | The check is neutralized **in the container copy** of the project (`package.sh`) — the real `vendor/` stays untouched. The image *is* native Linux; it's a false positive |
| H3 | `native:package` has no `--build-type=debug` (debug goes through `native:run` + adb, which doesn't exist in the container) | Build `release` signed with a `keytool`-generated keystore — sideloads exactly like a debug APK |
| H4 | `native:package` shells out to `rsync` for the bundle; the minimal Ubuntu image lacks it | `rsync` added to the Dockerfile |
| H5 | The bundle packs **everything** in the project dir — the previous APK and 60 MB of test uploads made a 147 MB APK | `nativephp.cleanup_exclude_files` in `config/nativephp.php` + `tar` exclusions when copying into the container → bundle back to ~10 MB |
| H6 | A global `find` for `*.apk` picked up *stale* APKs and overwrote the fresh one | Copy only `app/build/outputs/apk/release/`; keep the keystore in a named volume so signatures match across builds |

## What it does step by step

1. `tar` the project into `/work` (native container FS — Gradle over a Windows
   bind mount is needlessly slow), excluding `native-builds/`, spike data and
   `docker/` itself.
2. `composer install` + `native:install android`.
3. Inject `MANAGE_EXTERNAL_STORAGE` into the generated manifest (pending
   removal — the app writes to `Download/AirPHP` without it on API 34).
4. Generate/persist the signing keystore, then
   `native:package android --build-type=release --no-tty`.
5. Copy only the fresh APK from Gradle's output dir to `/out`.
