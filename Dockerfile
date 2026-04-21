FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libgmp-dev \
        curl \
    && docker-php-ext-install gmp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable modules needed by .htaccess (rewrite, cache headers)
RUN a2enmod rewrite expires headers

# Allow .htaccess overrides in docroot
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY Subnet-Calculator/ /var/www/html/
COPY testing/fixtures/iframe-test.html /var/www/html/

EXPOSE 80
