FROM node:24.21.0-bookworm-slim AS frontend
WORKDIR /frontend
COPY package.json package-lock.json tsconfig*.json vite.config.ts ./
COPY src ./src
COPY scripts ./scripts
COPY resources/data ./resources/data
RUN npm ci --no-audit --no-fund && npm run typecheck && npm run build
COPY tests/Frontend ./tests/Frontend
RUN node scripts/test-frontend-reconstruction.mjs && node scripts/test-session-client.mjs

FROM composer:2 AS composer
FROM php:8.4.27-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx chromium clamav clamav-daemon ca-certificates curl git unzip python3 mariadb-client \
    libicu-dev libzip-dev libxml2-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath gd intl mbstring pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=frontend /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN rm -rf public/build bootstrap/ssr
COPY --from=frontend /frontend/node_modules ./node_modules
COPY --from=frontend /frontend/public/build ./public/build
COPY --from=frontend /frontend/bootstrap/ssr ./bootstrap/ssr
ENV APP_ENV=testing APP_TIMEZONE=UTC APP_DEBUG=false MAIL_MAILER=array QUEUE_CONNECTION=database \
    NUERS_NOTIFICATION_DISPATCH_ENABLED=false NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED=false \
    NUERS_INFRA_CONTROLLER_ENABLED=false NUERS_INFRA_EXECUTION_ENABLED=false \
    NUERS_INFRA_BACKUP_ENABLED=false NUERS_PDF_CHROME=/usr/bin/chromium
RUN mkdir -p tests/Unit storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .local/vapt-remediation-2026-11-14 \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && printf 'memory_limit=512M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/zz-nuers.ini \
    && sed -i 's/^listen = 9000/listen = 127.0.0.1:9000/' /usr/local/etc/php-fpm.d/zz-docker.conf \
    && usermod -aG clamav www-data \
    && rm -f /etc/nginx/sites-enabled/default
COPY deployment/staging/nginx.conf /etc/nginx/conf.d/nuers.conf
COPY deployment/staging/container-start.sh /usr/local/bin/nuers-stage-start
RUN chmod 0755 /usr/local/bin/nuers-stage-start \
    && php -r 'if(PHP_VERSION!=="8.4.27")exit(1);'
CMD ["/usr/local/bin/nuers-stage-start"]
