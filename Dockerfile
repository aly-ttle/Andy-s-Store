FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && sed -i 's/^[[:space:]]*StartServers .*/StartServers 2/; s/^[[:space:]]*MinSpareServers .*/MinSpareServers 1/; s/^[[:space:]]*MaxSpareServers .*/MaxSpareServers 3/; s/^[[:space:]]*MaxRequestWorkers .*/MaxRequestWorkers 4/' /etc/apache2/mods-available/mpm_prefork.conf \
    && sed -i '/MaxRequestWorkers/i ServerLimit 4' /etc/apache2/mods-available/mpm_prefork.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN mkdir -p /var/www/html/uploads /var/www/html/products \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/products

EXPOSE 8080
CMD ["sh", "-c", "port=\"${PORT:-8080}\"; sed -i \"s/Listen 80/Listen ${port}/\" /etc/apache2/ports.conf && sed -i \"s|<VirtualHost \\*:80>|<VirtualHost *:${port}>|\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
