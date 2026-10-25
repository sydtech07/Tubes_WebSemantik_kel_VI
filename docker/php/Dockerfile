# Dipakai di Part 2 (Laravel 13 butuh PHP >= 8.3).
# Ekstensi mongodb diperlukan paket mongodb/laravel-mongodb.
FROM php:8.3-cli

# Instal dependensi sistem dan ekstensi PHP bawaan.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates \
    libzip-dev libicu-dev libssl-dev pkg-config \
 && docker-php-ext-install pdo_mysql zip intl bcmath \
 && rm -rf /var/lib/apt/lists/*

# Unduh paket MongoDB secara langsung, lalu pasang dari arsip lokal.
RUN curl -fSL --retry 3 --retry-delay 2 \
      https://github.com/mongodb/mongo-php-driver/releases/download/2.5.5/mongodb-2.5.5.tgz \
      -o /tmp/mongodb-2.5.5.tgz \
 && pecl install /tmp/mongodb-2.5.5.tgz \
 && docker-php-ext-enable mongodb \
 && rm -f /tmp/mongodb-2.5.5.tgz

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
EXPOSE 8000
