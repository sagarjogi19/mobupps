# Product Store — RESTful API + Web Client

Product catalog management for an online store:

- **Backend**: PHP 8 (no framework, zero runtime dependencies), SQLite via
  PDO, JWT (HS256) authentication.
- **Frontend**: vanilla JS single-page app (no build step) — paginated
  product list, debounced search & filters, auth-protected add/edit/delete
  forms with loading/success/error states.
- **Docs**: [`DESIGN.md`](DESIGN.md) (design document), [`openapi.yaml`](openapi.yaml)
  (OpenAPI 3 spec, served via Swagger UI at `/docs.html`),
  [`postman_collection.json`](postman_collection.json) (imports into Postman).

## Quick start (local)

Requirements: PHP ≥ 8.1 with `pdo_sqlite` (enabled by default).

```bash
cd backend
php -S localhost:8000 public/index.php
```

Then open <http://localhost:8000> — that's it. The SQLite database is
created and seeded automatically on first request (25 sample products and
a demo user).

| What | Where |
|---|---|
| Web UI | http://localhost:8000 |
| API base | http://localhost:8000/api |
| Swagger UI | http://localhost:8000/docs.html |
| Frontend unit tests | http://localhost:8000/tests/tests.html |

**Demo credentials:** `admin@example.com` / `admin123`

## Quick start (Docker)

```bash
docker compose up --build
```

Serves everything on <http://localhost:8000>; the SQLite file persists in
a named volume across restarts.

## Configuration

Copy `backend/.env.example` to `backend/.env` (optional — sensible dev
defaults apply). Real environment variables take precedence.

| Variable | Default | Description |
|---|---|---|
| `JWT_SECRET` | dev value | HMAC secret for signing tokens — **change outside dev** |
| `JWT_TTL` | `3600` | Access-token lifetime in seconds |
| `DB_PATH` | `backend/database/app.sqlite` | SQLite file location |
| `CORS_ALLOW_ORIGIN` | `*` | Allowed CORS origin |

## Database

SQLite is used so reviewers need zero setup. All queries go through PDO
prepared statements, so pointing `Database::connect()` at a MySQL/PostgreSQL
DSN is the only change needed to switch engines. Schema (auto-migrated on
boot): `users` and `products` with a UNIQUE SKU constraint and indexes on
`name`, `category` and `price`. Delete `backend/database/app.sqlite` to
reset to fresh seed data.

## API in 30 seconds

```bash
# login -> token
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"admin123"}'

# use it
curl -s "http://localhost:8000/api/products?q=mouse&page=1&per_page=10" \
  -H "Authorization: Bearer <TOKEN>"
```

Full endpoint reference, request/response envelopes, validation rules and
error codes: [`DESIGN.md`](DESIGN.md) or Swagger UI.

## Running the tests

Backend (PHPUnit — unit + integration, 41 tests):

```bash
cd backend
composer install   # dev dependency: phpunit only
composer test
```

Frontend (browser runner, no tooling needed): start the server and open
<http://localhost:8000/tests/tests.html> — all assertions run on load.

## Project structure

```
product-store/
├── backend/
│   ├── public/index.php        # front controller (API + static frontend)
│   ├── src/
│   │   ├── App.php             # composition root / routes
│   │   ├── Config.php          # env-based configuration
│   │   ├── Database.php        # PDO factory, migrations, seed
│   │   ├── Auth/               # JWT (HS256) implementation
│   │   ├── Http/               # Request, Response, Router
│   │   ├── Middleware/         # Bearer-token auth guard
│   │   ├── Controllers/        # Auth + Product endpoints
│   │   ├── Repositories/       # all SQL (prepared statements)
│   │   └── Validation/         # product payload validation
│   └── tests/                  # PHPUnit unit + integration suites
├── frontend/
│   ├── index.html              # SPA shell (login, list, modals)
│   ├── js/                     # api client, app logic, pure utils
│   ├── css/styles.css
│   ├── docs.html               # Swagger UI
│   └── tests/tests.html        # browser unit-test runner
├── openapi.yaml                # OpenAPI 3 specification
├── postman_collection.json     # Postman collection (auto-saves token)
├── DESIGN.md                   # design document
├── Dockerfile
└── docker-compose.yml
```
