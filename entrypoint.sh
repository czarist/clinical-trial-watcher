#!/bin/bash

# Espera o MySQL ficar disponível
echo "Waiting for MySQL..."
until nc -z mysql 3306; do
    echo "Waiting for MySQL at mysql:3306..."
    sleep 2
done

# Rodando composer install se necessário
if [ ! -d "vendor" ]; then
    echo "Running composer install..."
    composer install --no-interaction --prefer-dist
else
    echo "Vendor folder already exists, skipping composer install."
fi

# Gera app key se não existir
if ! grep -q "APP_KEY=base64" .env; then
    echo "Generating app key..."
    php artisan key:generate
else
    echo "App key already exists, skipping key generate."
fi

# Generate the JWT signing secret when it is missing or empty.
if ! grep -qE "^JWT_SECRET=.+" .env; then
    echo "Generating JWT secret..."
    php artisan jwt:secret --force
fi

# Rodando migrations
echo "Running migrations..."
php artisan migrate --force

# Files created by artisan commands run as root must stay writable by PHP-FPM (www-data).
chown -R www-data:www-data storage bootstrap/cache

# Iniciando PHP-FPM
echo "Starting PHP-FPM..."
exec php-fpm
