#!/usr/bin/env bash
# Empaqueta el APK debug dentro del contenedor.
# Uso (host): docker run --rm \
#   -v /ruta/al/proyecto:/src:ro \
#   -v airphp-gradle:/root/.gradle \
#   -v airphp-composer:/root/.composer \
#   -v /ruta/salida:/out \
#   airphp-android bash /src/docker/android/package.sh
#
# El proyecto se copia a /work (fs nativo del contenedor): Gradle sobre un
# bind mount de Windows es innecesariamente lento.
set -euo pipefail

WORK=/work
OUT=/out

echo "==> Copiando proyecto a $WORK"
mkdir -p "$WORK"
# Sin artefactos de builds ni datos del spike: ensucian el bundle y el find
# de APKs al final podría recoger una APK vieja en vez de la recién hecha.
tar -C /src -c --exclude='./native-builds' --exclude='./storage/app/spike' \
    --exclude='./docker' -f - . | tar -xf - -C "$WORK"
cd "$WORK"

# local.properties con el sdk.dir real (el template lo trae vacío)
echo "sdk.dir=$ANDROID_HOME" > nativephp/android/local.properties

echo "==> composer install"
composer install --no-interaction --prefer-dist --optimize-autoloader

# En CI el checkout no trae .env (gitignored): partir del ejemplo.
if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || touch .env
    php artisan key:generate --force -q || true
fi

# APP_ID / APP_VERSION / APP_VERSION_CODE inyectables por env (el release CI
# los deriva del tag v*); si no vienen, manda el .env del dev.
set_env() { grep -q "^$1=" .env && sed -i "s|^$1=.*|$1=$2|" .env || echo "$1=$2" >> .env; }
[ -n "${APP_ID:-}" ] && set_env NATIVEPHP_APP_ID "$APP_ID"
[ -n "${APP_VERSION:-}" ] && set_env NATIVEPHP_APP_VERSION "\"$APP_VERSION\""
[ -n "${APP_VERSION_CODE:-}" ] && set_env NATIVEPHP_APP_VERSION_CODE "\"$APP_VERSION_CODE\""

# native:install/native:run detectan WSL leyendo /proc/version; Docker Desktop
# en Windows comparte el kernel WSL2 y el check da falso positivo. Esto ES un
# Linux nativo, así que el bypass es correcto — y solo toca la copia de /work.
WSL_CHECK="vendor/nativephp/mobile/src/Concerns/PlatformFileOperations.php"
if grep -q "str_contains(\$version, 'Microsoft')" "$WSL_CHECK"; then
    echo "==> Neutralizando falso positivo de WSL en la copia del contenedor"
    sed -i "s/return str_contains(\$version, 'Microsoft') || str_contains(\$version, 'WSL');/return false;/" "$WSL_CHECK"
fi

echo "==> native:install android"
php artisan native:install android --no-interaction

# MANAGE_EXTERNAL_STORAGE permite escribir en /storage/emulated/0/AirPHP
# (carpeta visible). El usuario concede "Acceso a todos los archivos" en
# Ajustes una vez; sin el grant, el spike cae al Android/data de la app.
MANIFEST="nativephp/android/app/src/main/AndroidManifest.xml"
if ! grep -q "MANAGE_EXTERNAL_STORAGE" "$MANIFEST"; then
    echo "==> Agregando MANAGE_EXTERNAL_STORAGE al manifest"
    sed -i 's|<uses-permission android:name="android.permission.INTERNET" />|<uses-permission android:name="android.permission.INTERNET" />\n    <uses-permission android:name="android.permission.MANAGE_EXTERNAL_STORAGE" />|' "$MANIFEST"
fi

# native:package solo acepta release|bundle (debug va por native:run + adb,
# que no existe dentro del contenedor). El keystore vive en un volumen
# persistente para que las actualizaciones instalen encima (misma firma);
# en CI se monta uno decodificado desde secrets y sus credenciales viajan
# por env. Sin env, cae a los defaults de desarrollo (airphp123).
KEYSTORE=${KEYSTORE_PATH:-/keys/airphp.keystore}
STOREPASS=${KEYSTORE_PASSWORD:-airphp123}
ALIAS=${KEY_ALIAS:-airphp}
KEYPASS=${KEY_PASSWORD:-$STOREPASS}
if [ ! -f "$KEYSTORE" ]; then
    echo "==> Generando keystore de firma"
    keytool -genkeypair -v -keystore "$KEYSTORE" \
        -alias "$ALIAS" -keyalg RSA -keysize 2048 -validity 10000 \
        -storepass "$STOREPASS" -keypass "$KEYPASS" \
        -dname "CN=AirPHP Spike, OU=Dev, O=Local, L=Local, S=Local, C=MX"
fi

echo "==> native:package android --build-type=release"
php artisan native:package android --build-type=release --no-tty \
    --keystore="$KEYSTORE" \
    --keystore-password="$STOREPASS" \
    --key-alias="$ALIAS" \
    --key-password="$KEYPASS"

echo "==> Buscando APK generado"
# Solo la salida real de gradle — un find global recoge APKs viejas que
# quedaron dentro de la copia del proyecto (native-builds/, uploads/).
APK_DIR="$WORK/nativephp/android/app/build/outputs/apk/release"
mapfile -t APKS < <(find "$APK_DIR" -name "*.apk" -type f 2>/dev/null)
if [ "${#APKS[@]}" -eq 0 ]; then
    echo "ERROR: no se generó ningún .apk en $APK_DIR" >&2
    exit 1
fi

mkdir -p "$OUT"
for apk in "${APKS[@]}"; do
    echo "APK: $apk"
    cp "$apk" "$OUT"/
done

ls -la "$OUT"
