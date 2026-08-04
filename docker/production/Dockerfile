# Production image for Railway. Based on docker/8.5/Dockerfile (Sail dev image)
# but stripped of Xdebug, Playwright, and unused DB drivers/extensions.

# ---- frontend assets ----
FROM node:24-slim AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm install
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---- php app ----
FROM ubuntu:24.04

ARG POSTGRES_VERSION=18

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV LANG=C.UTF-8

RUN apt-get update && apt-get install -y --no-install-recommends \
        gnupg gosu curl ca-certificates zip unzip git supervisor \
        libcap2-bin libpng-dev librsvg2-bin ffmpeg \
        python3 python3-pip \
    && mkdir -p /etc/apt/keyrings \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libgd3 \
        php8.5-cli \
        php8.5-pgsql \
        php8.5-gd \
        php8.5-curl \
        php8.5-mbstring \
        php8.5-xml \
        php8.5-zip \
        php8.5-bcmath \
        php8.5-intl \
        php8.5-imagick \
        php8.5-readline \
    && curl -sLS https://getcomposer.org/installer | php -- --install-dir=/usr/bin/ --filename=composer \
    && curl -sS https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor | tee /etc/apt/keyrings/pgdg.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/pgdg.gpg] http://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-$POSTGRES_VERSION \
    && apt-get -y autoremove \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Pitch-detection DSP engine dependencies for the Aural/Tuning Fork endpoints.
# fastapi/uvicorn run pitch_analyzer.py as a persistent, supervisor-managed process
# so librosa/numba's import & JIT cost is paid once at container boot, not per-request.
RUN pip3 install --no-cache-dir --break-system-packages librosa numpy soundfile fastapi "uvicorn[standard]" python-multipart

RUN groupadd --force -g 1337 www && useradd -ms /bin/bash --no-user-group -g 1337 -u 1337 www

COPY --chown=www:www . /var/www/html
COPY --from=frontend --chown=www:www /app/public/build /var/www/html/public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chmod -R ug+rw storage bootstrap/cache \
    && php artisan storage:link || true

COPY docker/production/start-container /usr/local/bin/start-container
COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod +x /usr/local/bin/start-container

EXPOSE 80

ENTRYPOINT ["start-container"]
