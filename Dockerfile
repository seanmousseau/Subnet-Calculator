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

# v3.1.0 #324 — let PHP read the test-drain token via getenv(). The variable
# is supplied by docker-compose (Makefile generates it per `make test-docker`
# invocation; empty in plain `docker compose up`, in which case the drain
# endpoint 404s — fail closed).
RUN echo 'PassEnv PHPUNIT_TEST_DRAIN_TOKEN' >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY Subnet-Calculator/ /var/www/html/
COPY testing/fixtures/iframe-test.html /var/www/html/

# COPY runs as root and preserves uid 0 on the copied tree. Apache runs as
# www-data, which needs write access to data/ (SQLite session DB) and to any
# admin config file the first-run wizard may create at runtime. Hand both to
# www-data; everything else stays root-owned and read-only.
RUN chown -R www-data:www-data /var/www/html/data \
    && rm -f /var/www/html/data/sessions.sqlite \
    && chown www-data:www-data /var/www/html

# Test-rig configuration. Generated at build time so no bcrypt hash is
# committed to source — the plaintext "test-admin-password" is hashed
# fresh each build. This config is ONLY present in the test image; release
# tarballs ship without it (config.php.example shows the production shape).
#
# Enables:
#   - $session_enabled  : powers Playwright IPv4 + IPv6 VLSM session save/load tests
#   - $admin_ui_enabled : powers /admin/keys.php and /admin/audit.php tests
RUN HASH=$(php -r "echo password_hash('test-admin-password', PASSWORD_BCRYPT);") \
    && printf '<?php\n$session_enabled=true;\n$session_ttl_days=1;\n$admin_ui_enabled=true;\n$admin_user="testadmin";\n$admin_pass_hash=%s;\n$admin_audit_retention_days=1;\n' \
        "'$HASH'" > /var/www/html/config.php \
    && php -l /var/www/html/config.php

# www-data already owns /var/www/html inside the base image; no chown needed.
USER www-data

EXPOSE 8080
