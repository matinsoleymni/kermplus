FROM golang:1.23-bookworm AS builder

WORKDIR /app

COPY form/go.mod form/go.sum ./
RUN go mod download

COPY form/ ./

RUN CGO_ENABLED=0 GOOS=linux go build -o /bin/form-server main.go

RUN go run github.com/mxschmitt/playwright-go/cmd/playwright@latest install --with-deps chromium

FROM debian:bookworm-slim
WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libc6 \
    libcairo2 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
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
    libxkbcommon0 \
    libxrandr2 \
    libxrender1 \
    libxss1 \
    libxtst6 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /bin/form-server /usr/local/bin/form-server

COPY --from=builder /root/.cache/ms-playwright /root/.cache/ms-playwright

EXPOSE 8084
CMD ["form-server"]
