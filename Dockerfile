# Usa imagem oficial PHP 8.3 com FPM
FROM php:8.3-fpm

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    curl \
    libzip-dev \
    libpq-dev \
    libssl-dev \
    libicu-dev \
    libsqlite3-dev \
    libmagickwand-dev --no-install-recommends \
    netcat-openbsd  # para esperar o MySQL no entrypoint

# Instala extensões do PHP que Laravel geralmente usa
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd zip

# Instala Node.js 20 LTS + npm
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Instala Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Define o diretório de trabalho
WORKDIR /var/www/html

# Copia tudo pro container
COPY . .

# Permissões (opcional)
RUN [ -d "/var/www/html/storage" ] && chown -R www-data:www-data /var/www/html/storage || echo "Skipping storage chown" && \
    [ -d "/var/www/html/bootstrap/cache" ] && chown -R www-data:www-data /var/www/html/bootstrap/cache || echo "Skipping cache chown"

# Copia entrypoint
COPY ./entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Expõe a porta
EXPOSE 9000

# Comando padrão → entrypoint.sh cuida do migrate e inicia php-fpm
CMD ["/entrypoint.sh"]
