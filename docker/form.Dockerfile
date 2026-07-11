FROM golang:1.26-alpine AS builder
WORKDIR /src

COPY form/go.mod form/go.sum /src/
COPY form /src

RUN go mod tidy

RUN go build -o /bin/form-server main.go

FROM alpine:3.19
WORKDIR /app

COPY --from=builder /bin/form-server /usr/local/bin/form-server

EXPOSE 8084
CMD ["form-server"]
