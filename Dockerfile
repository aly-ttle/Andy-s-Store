FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN mkdir -p /var/www/html/uploads /var/www/html/products \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html/products

EXPOSE 8080
CMD ["sh", "-c", "port=\"${PORT:-8080}\"; sed -i \"s/Listen 80/Listen ${port}/\" /etc/apache2/ports.conf && sed -i \"s|<VirtualHost \\*:80>|<VirtualHost *:${port}>|\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
