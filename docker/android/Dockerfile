# Toolchain de build Android para NativePHP Mobile — Fase 0.2
# Versiones espejo de MrPunyapal/nativephp-build-action + el template
# nativo del proyecto (NDK 27.0.12077973, CMake 3.22.1, AGP 8.13.2 → JDK 17).
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    ANDROID_HOME=/opt/android-sdk \
    JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 \
    COMPOSER_ALLOW_SUPERUSER=1

# Sistema base + JDK 17
RUN apt-get update && apt-get install -y --no-install-recommends \
      openjdk-17-jdk-headless \
      curl unzip zip git ca-certificates rsync \
      software-properties-common \
    && rm -rf /var/lib/apt/lists/*

# PHP 8.4 CLI + extensiones que piden composer.json y native:install (ext-zip)
RUN add-apt-repository -y ppa:ondrej/php \
    && apt-get update && apt-get install -y --no-install-recommends \
      php8.4-cli php8.4-zip php8.4-mbstring php8.4-xml php8.4-curl \
      php8.4-sqlite3 php8.4-intl php8.4-bcmath \
    && rm -rf /var/lib/apt/lists/*

# Composer
RUN curl -sS https://getcomposer.org/installer \
      | php -- --install-dir=/usr/local/bin --filename=composer

# Node 22 (por si el build invoca herramientas JS)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Android command line tools
RUN mkdir -p $ANDROID_HOME/cmdline-tools/latest \
    && curl -fsSL https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip -o /tmp/tools.zip \
    && unzip -q /tmp/tools.zip -d /tmp/tools \
    && mv /tmp/tools/cmdline-tools/* $ANDROID_HOME/cmdline-tools/latest/ \
    && rm -rf /tmp/tools /tmp/tools.zip

ENV PATH="$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$PATH"

# SDK: plataforma 36, build-tools, NDK r27, CMake 3.22.1 (lo que pide el template)
RUN yes | sdkmanager --licenses > /dev/null \
    && sdkmanager --install \
        "platform-tools" \
        "platforms;android-36" \
        "build-tools;36.0.0" \
        "ndk;27.0.12077973" \
        "cmake;3.22.1"

WORKDIR /app
CMD ["bash"]
