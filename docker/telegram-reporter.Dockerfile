FROM python:3.12-slim AS runtime

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY telegram-reporter /app
RUN pip install --no-cache-dir --only-binary=:all: . || pip install --no-cache-dir .

EXPOSE 8082
CMD ["uvicorn", "api_server:api", "--host", "0.0.0.0", "--port", "8082"]

