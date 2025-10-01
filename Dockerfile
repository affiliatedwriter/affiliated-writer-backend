# PHP 8.2-FPM Alpine base image ব্যবহার করা হচ্ছে
FROM php:8.2-fpm-alpine

# PHP এক্সটেনশনের জন্য প্রয়োজনীয় সিস্টেম লাইব্রেরিগুলো ইন্সটল করা হচ্ছে
# pdo_sqlite-এর জন্য sqlite-dev এবং pdo_mysql-এর জন্য mariadb-dev অপরিহার্য
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

# ফাইলের সঠিক পারমিশন সেট করা হচ্ছে (Render-এর জন্য গুরুত্বপূর্ণ)
RUN chown -R www-data:www-data /app

# 9000 পোর্ট এক্সপোজ করে php-fpm সার্ভার চালু করা হচ্ছে
EXPOSE 9000
CMD ["php-fpm"]
