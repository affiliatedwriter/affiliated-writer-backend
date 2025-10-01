# Dockerfile (root)
FROM php:8.2-apache

# Apache: public/ কে ডকুমেন্টরুট বানাই, mod_rewrite চালু করি
RUN a2enmod rewrite
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# PDO + MySQL + SQLite
RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite

# Composer ঢুকাই
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# কোড কপি করে ডিপেন্ডেন্সি ইনস্টল
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction

# healthcheck (ঐচ্ছিক)
HEALTHCHECK --interval=30s --timeout=5s \
  CMD php -r 'echo "ok\n";'
