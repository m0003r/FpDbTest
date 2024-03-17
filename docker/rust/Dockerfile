FROM php:8.3-cli

# Install rustup
RUN apt-get update \
    && apt-get install -y curl clang libclang-dev \
    && rm -rf /var/lib/apt/lists/*

RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y

# Add cargo to PATH
ENV PATH="/root/.cargo/bin:${PATH}"

WORKDIR /app
ENTRYPOINT ["/root/.cargo/bin/cargo"]