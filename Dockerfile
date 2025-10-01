# PHP 8.2 Alpine base image ব্যবহার করা হচ্ছে
FROM php:8.2-alpine

# PHP এক্সটেনশনের জন্য প্রয়োজনীয় সিস্টেম লাইব্রেরিগুলো ইন্সটল করা হচ্ছে
RUN apk update && apk add --no-cache \
    $PHPIZE_DEPS \
    mariadb-dev \
    sqlite-dev \
    libzip-dev

# এখন প্রয়োজনীয় PHP এক্সটেনশনগুলো ইন্সটল করা হচ্ছে
RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite zip

# Composer ইন্সটল করা হচ্ছে
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# অ্যাপ্লিকেশনের জন্য ওয়ার্কিং ডিরেক্টরি সেট করা হচ্ছে
WORKDIR /app

# প্রথমে শুধু Composer ফাইল কপি করে dependency গুলো ইন্সটল করা হচ্ছে
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-dev --optimize-autoloader

# সবশেষে, বাকি সব অ্যাপ্লিকেশন কোড কপি করা হচ্ছে
COPY . .

# Render ডিফল্টভাবে 10000 পোর্ট ব্যবহার করে, তাই আমরা সেটি এক্সপোজ করছি
EXPOSE 10000

# PHP-এর বিল্ট-ইন ওয়েব সার্ভার চালু করা হচ্ছে
# এই কমান্ডটি একটি আসল ওয়েব সার্ভার চালু করবে যা Render খুঁজে পাবে
CMD [ "php", "-S", "0.0.0.0:10000", "-t", "public" ]