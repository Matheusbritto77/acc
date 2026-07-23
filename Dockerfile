FROM php:8.3-apache

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		ca-certificates \
		curl \
		libicu-dev \
		libpng-dev \
		libzip-dev \
		libonig-dev \
		libxml2-dev \
		unzip \
	&& docker-php-ext-install -j"$(nproc)" gd intl mysqli opcache pdo_mysql zip bcmath mbstring xml \
	&& a2enmod headers rewrite \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy the local CanaryAAC codebase
COPY . /var/www/html/

WORKDIR /var/www/html

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
	&& chown -R www-data:www-data /var/www/html

COPY --chmod=755 entrypoint.sh /usr/local/bin/canaryaac-entrypoint
COPY bootstrap.php /usr/local/bin/canaryaac-bootstrap.php

ENTRYPOINT ["canaryaac-entrypoint"]
CMD ["apache2-foreground"]
