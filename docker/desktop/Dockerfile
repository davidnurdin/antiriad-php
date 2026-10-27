# JumPHP desktop runtime: native PHP 8.3 + the SDL extensions, built from the SAME forks and commits
# as the WebAssembly engine (docker/engine-wasm), so a game sees the exact same SDL API on both targets.
# Also used for composer, phpunit, phpstan and headless smoke tests (SDL dummy drivers).
FROM php:8.3-cli-bookworm

ARG PHP_SDL_REF=89855c8f4f63b77f9a548007b9c4afe366785b4b
ARG SDL_IMAGE_REF=fd798eb2c52b7be5874a30334d1d255436aa686e
ARG SDL_MIXER_REF=f75a31826f4484bb4af31c1a1a4cdf1463aae8b2
ARG SDL_TTF_REF=d2c634c509aa0b14168f55128435d9519d34d0e3

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip pkg-config \
        libsdl2-dev libsdl2-image-dev libsdl2-ttf-dev libsdl2-mixer-dev \
        libpng-dev libzip-dev \
        libgl1-mesa-dri libegl1 libgles2 libpulse0 \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" gd bcmath pcntl zip

# fetch <repo> <ref> <dir>: one pinned commit, no history
RUN set -eux; \
    fetch() { mkdir -p "$3" && cd "$3" && git init -q && git remote add origin "https://github.com/$1.git" \
              && git fetch -q --depth 1 origin "$2" && git checkout -q FETCH_HEAD; }; \
    fetch davidnurdin/php-sdl   "$PHP_SDL_REF"   /usr/src/ext-sdl; \
    fetch kea/php-sdl-image     "$SDL_IMAGE_REF" /usr/src/ext-sdl_image; \
    fetch kea/php-sdl-mixer     "$SDL_MIXER_REF" /usr/src/ext-sdl_mixer; \
    fetch kea/php-sdl-ttf       "$SDL_TTF_REF"   /usr/src/ext-sdl_ttf; \
    build() { cd "$1" && phpize && ./configure $2 && make -j"$(nproc)" && make install; }; \
    build /usr/src/ext-sdl       --with-sdl; \
    build /usr/src/ext-sdl_image --with-sdl_image; \
    build /usr/src/ext-sdl_mixer --with-sdl_mixer; \
    build /usr/src/ext-sdl_ttf   --enable-sdl_ttf; \
    docker-php-ext-enable sdl sdl_image sdl_mixer sdl_ttf; \
    rm -rf /usr/src/ext-*; \
    php -m | grep -iE '^(sdl|sdl_image|sdl_mixer|sdl_ttf|gd)$'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# same limits as the browser build would hit first: keep the desktop honest
RUN { echo 'memory_limit=512M'; echo 'error_reporting=E_ALL'; echo 'display_errors=stderr'; } \
        > /usr/local/etc/php/conf.d/zz-jumphp.ini

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    JUMPHP_PLATFORM=desktop

WORKDIR /game
CMD ["php", "app.php"]
