FROM php:8.1.8-apache
RUN set -eux; \
  apt-get update; \
  apt-get install -y libcurl4-openssl-dev pkg-config libssl-dev zlib1g-dev zip git; \
  pecl install mongodb; \
  docker-php-ext-enable mongodb;
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
RUN set -eux; \
  a2enmod rewrite; \
  sed -i 's!/var/www/html!/var/www/public!g' /etc/apache2/sites-available/000-default.conf
EXPOSE 80
