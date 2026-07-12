FROM golang:1.26-alpine AS builder
WORKDIR /src

COPY form/go.mod form/go.sum /src/
COPY form /src

RUN go mod tidy
RUN go build -o /bin/form-server main.go

FROM alpine:3.19
WORKDIR /app

# 1. Install Alpine's native Chromium and required dependencies
# We also install fonts so the headless browser can render text properly
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    font-noto-emoji

COPY --from=builder /bin/form-server /usr/local/bin/form-server

EXPOSE 8084
CMD ["form-server"]
