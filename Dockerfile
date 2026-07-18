# Product Store — single-container image serving both the API and the web UI.
# For a demo/assignment the PHP built-in server is sufficient; a production
# deployment would use php-fpm behind nginx (see DESIGN.md trade-offs).

FROM php:8.3-cli-alpine

WORKDIR /app

COPY backend/ ./backend/
COPY frontend/ ./frontend/

RUN mkdir -p /app/backend/database

ENV DB_PATH=/app/backend/database/app.sqlite \
    JWT_SECRET=change-me-in-production \
    JWT_TTL=3600 \
    CORS_ALLOW_ORIGIN=*

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=3s \
  CMD php -r "exit(str_contains(@file_get_contents('http://localhost:8000/api/health'), '\"ok\"') ? 0 : 1);"

CMD ["php", "-S", "0.0.0.0:8000", "backend/public/index.php"]
