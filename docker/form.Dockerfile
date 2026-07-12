# ==========================================
# Stage 1: Build the Go binary
# ==========================================
FROM golang:1.26-alpine AS builder
WORKDIR /src

# Copy module files and download dependencies
COPY form/go.mod form/go.sum /src/
COPY form /src

RUN go mod tidy
RUN go build -o /bin/form-server main.go

# ==========================================
# Stage 2: Final runtime image (Debian)
# ==========================================
FROM debian:bookworm-slim
WORKDIR /app

# Install all the necessary dependencies for the downloaded Chromium binary
RUN apt-get update && apt-get install -y \
    ca-certificates \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libc6 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libexpat1 \
    libfontconfig1 \
    libgbm1 \
    libgcc1 \
    libglib2.0-0 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libstdc++6 \
    libx11-6 \
    libx11-xcb1 \
    libxcb1 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxrandr2 \
    libxrender1 \
    libxss1 \
    libxtst6 \
    lsb-release \
    wget \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# Copy the compiled binary from Stage 1 (the "builder" stage)
COPY --from=builder /bin/form-server /usr/local/bin/form-server

EXPOSE 8084
CMD ["form-server"]
