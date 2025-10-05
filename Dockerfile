# PHP 8.3 + PDO (sqlite, mysql)
FROM php:8.3-cli

# System deps (git, unzip for composer) + sqlite dev headers
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip pkg-config libsqlite3-dev \
 && docker-php-ext-configure pdo_sqlite --with-pdo-sqlite \
 && docker-php-ext-install pdo pdo_sqlite pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install composer + deps
RUN php -r "copy('https://getcomposer.org/installer','composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm composer-setup.php \
 && composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction

# Copy the rest
COPY . .

# Ensure runtime dir for SQLite (ephemeral on Render)
RUN mkdir -p /tmp && chmod -R 777 /tmp

EXPOSE 10000

# Start PHP built-in server (MVP)
CMD ["php", "-S", "0.0.0.0:10000", "-t", "public"]
