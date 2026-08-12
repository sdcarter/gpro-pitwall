FROM php:8.5-apache

# Only intl needs compilation — pdo_sqlite, sqlite3, curl, mbstring are bundled in the apache image
RUN apt-get update && apt-get install -y libicu-dev unzip \
    && docker-php-ext-install intl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY apache.conf /etc/apache2/sites-available/000-default.conf

# Inject corporate CA cert if present (needed when a proxy does SSL inspection).
# See instructions below for how to generate docker-certs/corp-ca.crt
COPY docker-certs/ /tmp/docker-certs/
RUN if [ -s /tmp/docker-certs/corp-ca.crt ]; then \
        cp /tmp/docker-certs/corp-ca.crt /usr/local/share/ca-certificates/corp-ca.crt && \
        update-ca-certificates; \
    fi

# Copy only the dependency manifests first so this layer is cached unless they change
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist
