# syntax=docker/dockerfile:1

# হালকা ও সোজা—PHP built-in server ইউজ করবো, Render এর $PORT-এ লিসেন করবে
FROM php:8.2-cli

WORKDIR /app

# composer ইনস্টল
RUN apt-get update && apt-get install -y unzip git \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && rm -rf /var/lib/apt/lists/*

# প্রথম ধাপে composer ফাইল কপি -> ডিপেন্ডেন্সি ইনস্টল (Docker layer cache কাজে লাগবে)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# এরপর প্রজেক্টের বাকিটা কপি
COPY . .

# অটোলোড রিফ্রেশ (কোড কপি করার পর)
RUN composer dump-autoload -o

# Render সাধারণত $PORT দেয়; লোকালি 8080 এক্সপোজ করলাম
EXPOSE 8080
ENV PORT=8080

# public/ থেকে PHP server চালু (Slim এর index.php এখানেই)
CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t public"]
