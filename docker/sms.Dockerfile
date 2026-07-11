FROM golang:1.26-alpine AS builder
WORKDIR /src

COPY sms/go.mod sms/go.sum /src/
COPY sms /src

RUN go build -o /bin/sms-server app.go

FROM alpine:3.19
WORKDIR /app

COPY --from=builder /bin/sms-server /usr/local/bin/sms-server

EXPOSE 8083
CMD ["sms-server"]
