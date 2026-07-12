# ==========================================
# Stage 1: Build the Go binary
# ==========================================
FROM golang:1.26-alpine AS builder
WORKDIR /src

# 1. Copy ONLY module files first to leverage Docker layer caching
COPY form/go.mod form/go.sum ./

# 2. Download dependencies (this layer caches until go.mod/go.sum changes)
RUN go mod download

# 3. Copy the rest of the source code
COPY form/ ./

# 4. Build with BuildKit caches and disable CGO for cross-libc compatibility
RUN --mount=type=cache,target=/root/.cache/go-build \
    --mount=type=cache,target=/go/pkg/mod \
    CGO_ENABLED=0 GOOS=linux go build -o /bin/form-server main.go

# ==========================================
# Stage 2: Final runtime image (Debian)
# ==========================================
# (Keep your Stage 2 exactly as it is)
