FROM composer:2 AS composer

FROM php:8.4-apache

# ---------------------------------------------------------
# System packages
# ---------------------------------------------------------
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxml2-dev \
    libxslt1-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libssl-dev \
    libsodium-dev \
    libreadline-dev \
    libedit-dev \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------
# PHP extensions
# ---------------------------------------------------------
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        ctype \
        curl \
        dom \
        fileinfo \
        ftp \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        soap \
        sockets \
        sodium \
        xsl \
        zip

# ---------------------------------------------------------
# Composer
# ---------------------------------------------------------
COPY --from=composer /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/magento.ini

# ---------------------------------------------------------
# Apache
# ---------------------------------------------------------
RUN a2enmod rewrite

ENV APACHE_DOCUMENT_ROOT=/var/www/html/pub

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

RUN printf '%s\n' \
    '<Directory /var/www/html/pub>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/magento.conf \
    && a2enconf magento

# ---------------------------------------------------------
# Magento application
# ---------------------------------------------------------
WORKDIR /var/www/html

COPY . /var/www/html

# ---------------------------------------------------------
# Magento dependencies
# ---------------------------------------------------------
RUN composer config --global process-timeout 1800 \
    && composer install --no-interaction --prefer-dist

# ---------------------------------------------------------
# Permissions
# ---------------------------------------------------------
RUN chown -R www-data:www-data \
        /var/www/html/var \
        /var/www/html/generated \
        /var/www/html/pub/static \
        /var/www/html/pub/media \
        /var/www/html/app/etc

EXPOSE 80

CMD ["apache2-foreground"]