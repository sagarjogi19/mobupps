# Product Store API — Design Document

A RESTful API for managing an online store's product catalog, plus a web
client. Backend is dependency-free PHP 8 (no framework), storage is SQLite
via PDO, and authentication is JWT (HS256).

---

## 1. API structure and endpoints

All endpoints live under the `/api` prefix and speak JSON.

| Method | Endpoint                   | Auth | Description                                    |
|--------|----------------------------|------|------------------------------------------------|
| GET    | `/api/health`              | no   | Liveness check                                 |
| POST   | `/api/auth/login`          | no   | Exchange credentials for a JWT                 |
| GET    | `/api/auth/me`             | yes  | Current user (used to validate stored tokens)  |
| GET    | `/api/products`            | yes  | Paginated list with search, filter and sort    |
| GET    | `/api/products/{id}`       | yes  | Single product                                 |
| GET    | `/api/products/categories` | yes  | Distinct categories (drives the filter UI)     |
| POST   | `/api/products`            | yes  | Create a product                               |
| PUT    | `/api/products/{id}`       | yes  | Update a product (partial semantics)           |
| PATCH  | `/api/products/{id}`       | yes  | Alias of PUT                                   |
| DELETE | `/api/products/{id}`       | yes  | Delete a product                               |

### List query parameters (`GET /api/products`)

| Param       | Type    | Default      | Notes                                              |
|-------------|---------|--------------|----------------------------------------------------|
| `q`         | string  | —            | Substring match on name, description and SKU       |
| `category`  | string  | —            | Exact match                                        |
| `min_price` | number  | —            | Inclusive lower bound                              |
| `max_price` | number  | —            | Inclusive upper bound                              |
| `in_stock`  | boolean | false        | Only products with `stock > 0`                     |
| `sort`      | enum    | `created_at` | `name, price, stock, category, created_at, updated_at, id` (whitelisted) |
| `order`     | enum    | `desc`       | `asc` or `desc`                                    |
| `page`      | int     | 1            | 1-based                                            |
| `per_page`  | int     | 10           | Capped at 100 to protect the server                |

Architecture layers (each in its own namespace under `backend/src/`):

- **Http** — `Request`/`Response` value objects and a small regex `Router`
  with per-route middleware. Requests are plain objects, so the entire app
  can be exercised in tests without a web server.
- **Middleware** — `AuthMiddleware` verifies the Bearer JWT.
- **Controllers** — translate HTTP to domain calls, no business logic.
- **Validation** — `ProductValidator` normalizes and validates payloads.
- **Repositories** — all SQL, behind prepared statements.
- **App** — composition root wiring config, PDO and routes.

## 2. Authentication mechanism

- **JWT (HS256)**, implemented in ~60 auditable lines (`src/Auth/Jwt.php`)
  to honour the "no heavy dependencies" constraint. Signature verification
  uses `hash_equals` (constant-time); expiry (`exp`) is enforced.
- `POST /api/auth/login` verifies the password against a bcrypt hash
  (`password_hash`/`password_verify`) and returns a token valid for
  `JWT_TTL` seconds (default 1 hour). Unknown email and wrong password
  return the *same* error, so account existence is not leaked.
- Every `/api/products*` route (including reads, per the assignment) and
  `/api/auth/me` require an `Authorization: Bearer <token>` header;
  anything missing/invalid/expired gets `401` with a machine-readable code.
- Seeded demo user: `admin@example.com` / `admin123`.

## 3. Request/response structure

Requests: JSON bodies (`Content-Type: application/json`).

Every success response has the same envelope:

```json
{ "success": true, "data": { }, "meta": { } }
```

`meta` appears only on paginated lists:

```json
{ "page": 1, "per_page": 10, "total": 25, "total_pages": 3, "has_next": true, "has_prev": false }
```

Every error response:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed.",
    "details": { "price": "Price cannot be negative." }
  }
}
```

Status codes used: `200` OK, `201` Created, `204` No Content (delete),
`401` Unauthorized, `404` Not Found, `405` Method Not Allowed,
`409` Conflict (duplicate SKU), `422` Validation Error, `500` Internal.
`error.code` is a stable machine-readable string; `details` maps field
names to messages so clients can highlight specific inputs.

## 4. Data assumptions and validations

Product model:

| Field         | Type    | Rules                                                        |
|---------------|---------|--------------------------------------------------------------|
| `id`          | int     | Auto-increment primary key                                   |
| `name`        | string  | Required, trimmed, 1–160 chars                               |
| `sku`         | string  | Required, unique, normalized to uppercase, `[A-Z0-9][A-Z0-9-_.]*`, ≤40 chars |
| `description` | string  | Optional, ≤2000 chars, defaults to `""`                      |
| `price`       | decimal | Required, `0 ≤ price ≤ 1,000,000`, rounded to 2 dp           |
| `category`    | string  | Required, free text ≤80 chars (kept simple; see trade-offs)  |
| `stock`       | int     | Optional, `0 ≤ stock ≤ 1,000,000`, defaults to 0             |
| timestamps    | string  | `created_at` / `updated_at` maintained by the server (UTC)   |

Other assumptions:

- Single currency (USD) — no currency field.
- One admin role; any authenticated user can perform all operations.
- `PUT` uses partial semantics (only provided fields change). Strict REST
  would reserve that for `PATCH`; both verbs are accepted and behave the
  same, which is friendlier for clients.
- Deletes are hard deletes (see trade-offs).
- Search is SQL `LIKE` on name/description/SKU with `%`/`_` escaped.
- Sort column is validated against a whitelist — user input never reaches
  SQL as identifiers; values always go through bound parameters.

## 5. Example usage scenarios (cURL)

```bash
# 1) Login and capture the token
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"admin123"}' \
  | python -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

# 2) Paginated list, page 2, 5 per page, cheapest first
curl -s "http://localhost:8000/api/products?page=2&per_page=5&sort=price&order=asc" \
  -H "Authorization: Bearer $TOKEN"

# 3) Search "wireless" in Electronics between $10 and $100
curl -s "http://localhost:8000/api/products?q=wireless&category=Electronics&min_price=10&max_price=100" \
  -H "Authorization: Bearer $TOKEN"

# 4) Create a product  -> 201
curl -s -X POST http://localhost:8000/api/products \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"4K Monitor 27\"","sku":"ELC-0100","price":329.99,"category":"Electronics","stock":18}'

# 5) Partial update (only price and stock)  -> 200
curl -s -X PUT http://localhost:8000/api/products/26 \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"price":299.99,"stock":25}'

# 6) Delete  -> 204
curl -s -X DELETE http://localhost:8000/api/products/26 \
  -H "Authorization: Bearer $TOKEN" -o /dev/null -w "%{http_code}\n"

# 7) Error cases
curl -s http://localhost:8000/api/products                        # 401 no token
curl -s -X POST http://localhost:8000/api/products \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"","price":-5}'                                     # 422 field errors
```

A ready-to-import **Postman collection** (`postman_collection.json`) is
included — the Login request stores the token in a collection variable
automatically. **Swagger UI** is served at `http://localhost:8000/docs.html`
(spec: `openapi.yaml`).

## 6. Trade-offs and assumptions

| Decision | Why | Production alternative |
|---|---|---|
| SQLite | Zero-setup for reviewers; identical PDO code path | Swap DSN to MySQL/PostgreSQL; repositories stay unchanged |
| Hand-rolled JWT | Assignment discourages heavy deps; ~60 lines, constant-time comparison | `firebase/php-jwt`, key rotation, `kid` header |
| No refresh tokens | Keeps scope focused; 1h expiry limits exposure | Short-lived access + rotating refresh tokens (httpOnly cookie) |
| Token in `localStorage` | Simple SPA integration; XSS surface mitigated by output escaping | httpOnly `SameSite` cookies + CSRF token |
| Offset pagination | Simple, works for admin-scale catalogs | Keyset/cursor pagination for very large tables |
| `LIKE` search | No extra infra | SQLite FTS5 / PostgreSQL `tsvector` / OpenSearch |
| Hard delete | Simpler API and tests | Soft delete (`deleted_at`) to preserve order-history references |
| Free-text category | Avoids a second CRUD resource | `categories` table + FK |
| Single role | Assignment needs "authorized users" only | Role claims in JWT + per-route authorization middleware |
| PHP built-in server (dev/Docker) | One command, no extra images | nginx + php-fpm, HTTPS termination, rate limiting on `/auth/login` |
| No login rate limiting | Out of scope for demo | Fixed-window counter per IP (Redis) or fail2ban at the proxy |

Scalability notes: the app is stateless (JWT carries the session), so it
scales horizontally behind a load balancer with no sticky sessions; the only
stateful piece is the database. Indexes exist on `name`, `category`, and
`price`; `sku` is covered by its UNIQUE constraint.

## 7. Tests

Backend — PHPUnit, 41 tests / 136 assertions (`backend/tests/`):

- **Unit** (`tests/Unit`): JWT round-trip, expiry, tampering, wrong-secret
  and malformed-token rejection; validator normalization (trim, uppercase
  SKU, price rounding), required fields, boundary and type errors.
- **Integration** (`tests/Integration`): the full app is booted against an
  in-memory SQLite DB and driven through `Request` objects exactly as HTTP
  would — auth guard on every route, login flows, pagination math, search,
  category+price filters, sorting, SQL-injection attempt on `sort`,
  create/update/delete happy paths, duplicate-SKU conflicts, 404/405/422s.

Run: `cd backend && composer install && composer test`

Frontend — dependency-free browser test runner at
`http://localhost:8000/tests/tests.html` covering the pure utilities:
`debounce` (collapsing, re-firing, latest-args), `buildQuery`
(skipping empties, URL encoding), `formatPrice`, and `escapeHtml`
(XSS neutralization).
