FROM golang:1.21-alpine AS builder

WORKDIR /app
COPY apk/main.go .

RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o apk-service main.go

FROM debian:bookworm-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    zip \
    unzip \
    default-jre-headless \
    zipalign \
    apksigner \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /app/output /app/keystores

COPY --from=builder /app/apk-service /usr/local/bin/apk-service

ENV BASE_APK_PATH=/app/base.apk
ENV OUTPUT_DIR=/app/output
ENV KEYSTORE_DIR=/app/keystores
ENV LISTEN_ADDR=:8080
ENV KEYSTORE_PASS="gthjkdc56789"

EXPOSE 8080

CMD ["apk-service"]
