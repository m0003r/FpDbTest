# syntax = edrevo/dockerfile-plus

FROM php:8.3-cli AS rust_builder

# Install rustup
RUN apt-get update \
    && apt-get install -y curl clang libclang-dev \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y

COPY fpdb-rs /app

RUN cd /app && /root/.cargo/bin/cargo build --release

FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli zip opcache

RUN pecl install pcov && docker-php-ext-enable pcov

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY --from=rust_builder /app/target/release/libfpdb_rs.so /tmp/libfpdb_rs.so

RUN mv /tmp/libfpdb_rs.so `php -d 'display_errors=stderr' -r 'echo ini_get("extension_dir");')` && docker-php-ext-enable libfpdb_rs

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /app
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
