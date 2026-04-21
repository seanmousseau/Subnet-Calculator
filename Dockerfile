FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libgmp-dev \
        curl \
    && docker-php-ext-install gmp \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable modules needed by .htaccess (rewrite, cache headers)
RUN a2enmod rewrite expires headers

# Allow .htaccess overrides in docroot
RUN sed -ri '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Run Apache on an unprivileged port so the container can drop to www-data.
# Update the default VirtualHost and the main Listen directive.
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-enabled/000-default.conf

WORKDIR /var/www/html
COPY Subnet-Calculator/ /var/www/html/
COPY testing/fixtures/iframe-test.html /var/www/html/

# www-data already owns /var/www/html inside the base image; no chown needed.
USER www-data

EXPOSE 8080
