# Multiplayer relay / lobby / WebRTC signalling server of php-wasm-network (plain PHP, no dependency).
# Same commit as the engine's wasmnet extension (docker/engine-wasm NETWORK_REF).
FROM php:8.3-cli-alpine
ARG NETWORK_REF=a898904e58d445501dc98e5d2cf029632c449618
RUN apk add --no-cache git \
    && docker-php-ext-install pcntl \
    && mkdir /app && cd /app && git init -q && git remote add origin https://github.com/davidnurdin/php-wasm-network.git \
    && git fetch -q --depth 1 origin "$NETWORK_REF" && git checkout -q FETCH_HEAD && rm -rf .git \
    && apk del git
ENV PHP_WASM_NETWORK_LISTEN=tcp://0.0.0.0:8090
EXPOSE 8090
USER nobody
CMD ["php", "-d", "memory_limit=256M", "/app/server/bin/server.php"]
