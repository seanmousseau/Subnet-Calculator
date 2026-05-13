# API Contract

**Audience:** Developer / API consumer / future-agent making API changes.
**Companion to:** `Subnet-Calculator/api/openapi.yaml` (the spec).
**Premise:** OpenAPI is the *spec*. This document is the *policy*: what's a breaking change, how versioning works, how deprecation is announced, what the envelope guarantees.

---

## Versioning

The API is versioned in the URL path: `/api/v1/...`.

- **`v1`** = the current major version.
- A new major version (`v2`) ships only when a breaking change is unavoidable.
- The app's release version (e.g., `3.7.0`) is independent of the API version. App `3.x.y` and `4.x.y` are both expected to expose `v1`. The `v2` jump is reserved for genuine API breakage.

**`v2` will require:**
- A clear migration guide.
- A deprecation window for `v1` (minimum 6 months).
- Both versions live concurrently during the window.
- `Deprecation` and `Sunset` headers on `v1` responses during the window.

---

## What counts as a breaking change

| Change | Breaking? | Why |
|---|---|---|
| Add a new endpoint | No | Existing consumers ignore it. |
| Add an optional request field | No | Consumers can omit it. |
| Add a response field | No | JSON consumers ignore unknown fields. |
| Add a response field that consumers were reading via `Object.keys()` | **Maybe** | Defensive consumers shouldn't, but real-world consumers do. Call it out in CHANGELOG. |
| Rename a request field | **Yes** | Existing payloads break. |
| Rename a response field | **Yes** | Existing parsers break. The `ul_bit_flipped` rename is parked behind v4.0.0 for this reason. |
| Remove an endpoint | **Yes** | 404 where 200 was expected. |
| Remove a response field | **Yes** | Parsers reading the field break. |
| Tighten input validation (reject inputs that used to be accepted) | **Yes** | Surprises existing clients. PMTU cap in v3.6.3 was technically breaking but justified by DoS risk — documented prominently. |
| Loosen input validation | No | Existing clients still work. |
| Change error code in `error.code` | **Yes** | Clients switch on it. |
| Add a new error code | No | Clients should treat unknown codes as generic failures. |
| Change HTTP status code | **Yes** (usually) | Clients switch on status. |
| Reorder response fields | No | JSON is unordered. |
| Reorder array elements (where order isn't documented) | No | But if order *is* documented, yes. |
| Change auth requirement on an endpoint | **Yes** | Was anonymous → now requires key = breaking. |
| Add a new auth method | No | Existing auth still works. |
| Tighter rate limit | Soft-breaking | Existing burst patterns may now hit 429. Document. |
| Looser rate limit | No | — |

When in doubt, treat it as breaking. The cost of an over-cautious CHANGELOG note is zero; the cost of breaking a client is real.

---

## Response envelope

Every endpoint returns one of two shapes:

### Success

```json
{
  "ok": true,
  "data": { ...endpoint-specific payload... }
}
```

- HTTP status: `200` (or `201` for resource creation, e.g., `POST /api/v1/sessions`).
- `data` is always an object. Never a bare array, never a scalar.
- Endpoint-specific fields live inside `data`. The envelope itself is invariant.

### Error

```json
{
  "ok": false,
  "error": {
    "code": "INVALID_INPUT",
    "message": "human-readable explanation"
  }
}
```

- HTTP status: `4xx` for caller errors, `5xx` for server errors.
- `error.code` is a stable identifier. `error.message` is human-readable and may be tuned for clarity over releases.
- Clients **must switch on `error.code`**, not `error.message`.

### Error codes (stable surface)

| Code | HTTP | Meaning |
|---|---|---|
| `INVALID_INPUT` | 400 | Malformed or out-of-range request body / query param |
| `MISSING_FIELD` | 400 | A required field is absent |
| `METHOD_NOT_ALLOWED` | 405 | Wrong HTTP method for this endpoint |
| `UNAUTHORIZED` | 401 | Missing or invalid `Authorization` header |
| `FORBIDDEN` | 403 | Authenticated but not permitted for this resource (admin-only endpoints) |
| `RATE_LIMITED` | 429 | Per-IP or per-key rate limit hit |
| `NOT_FOUND` | 404 | No matching session / resource |
| `PAYLOAD_TOO_LARGE` | 413 | Request body or computed result exceeds bounds (PMTU caps, splitter caps, etc.) |
| `INTERNAL_ERROR` | 500 | Unhandled exception — accompanied by an entry in the operator's error log |

Adding a new code is non-breaking. Renaming or removing a code is **breaking**.

---

## Authentication

| Mode | Header | Rate limit | Notes |
|---|---|---|---|
| Anonymous | none | Per-IP, tight | Default for all endpoints unless documented otherwise |
| API key | `Authorization: Bearer <key>` | Per-key, configurable | Issued via admin console |
| Admin | cookie session (TOTP) | Per-IP login limit | UI only; not for programmatic consumers |

API keys never appear in URLs or request bodies. Never log them. Never echo them in responses (except in the admin issuance flow, exactly once).

---

## Content types

- Request body: `application/x-www-form-urlencoded` **or** `application/json`. The router accepts both for backwards compatibility with form-style consumers (and the original web UI used form encoding before any API existed).
- Response body: `application/json; charset=utf-8`.
- CORS: `Access-Control-Allow-Origin: *` for read-only endpoints; preflight for write endpoints; `Access-Control-Expose-Headers` includes `X-Request-Id`.

---

## Idempotency

- All `GET` endpoints are idempotent (HTTP guarantee).
- All `POST` calculator endpoints (ipv4, ipv6, vlsm, split, etc.) are idempotent in practice — same input always yields same output. They use `POST` because input may be longer than safe URL length, not because they mutate state.
- `POST /api/v1/sessions` is the only true write endpoint (creates a row in `data/sessions.sqlite`). It is **not** idempotent — each call creates a new session with a new ID.
- Admin write endpoints (`/api/v1/admin/keys` etc.) are not idempotent.

---

## Pagination

Not currently used. If/when added (e.g., listing API keys for an operator with many), the convention will be:
- Cursor-based, not offset-based.
- `next_cursor` in response if more results exist.
- `?cursor=<opaque>` in next request.

Avoid offset pagination — it breaks under concurrent inserts and doesn't scale.

---

## Deprecation policy

When deprecating an endpoint or field:

1. **Announce in CHANGELOG.md** under the release that introduces the deprecation.
2. Add **`Deprecation: true`** response header (and **`Sunset: <RFC 3339 date>`** if a removal date is known) per [RFC 8594](https://www.rfc-editor.org/rfc/rfc8594.html).
3. Update OpenAPI spec with `deprecated: true` on the endpoint or schema property.
4. Minimum window before removal: **6 months**.
5. Remove only in a major version bump (i.e., `v2`).

This pattern is not yet exercised. The first opportunity is the v4.0.0 `ul_bit_flipped` rename.

---

## Backwards compatibility commitments

In `v1`, we commit to:

- Endpoint paths under `/api/v1/` will not change.
- Response envelope shape (`ok` + `data`/`error`) will not change.
- Documented `error.code` values will not be renamed or removed.
- Optional request fields will remain optional.
- Required request fields will not be added retroactively.

We do **not** commit to:

- Stability of internal `data` shapes if marked `experimental: true` in OpenAPI. (Currently nothing is.)
- Wire-format stability of *new* fields added mid-version. Best-effort, but not contractual.
- Performance characteristics. A future release may make a fast endpoint slower or vice versa.

---

## OpenAPI spec — source of truth

`api/openapi.yaml` is the source of truth for:
- Endpoint paths + methods.
- Request schemas.
- Response schemas.
- Auth requirements.
- Examples.

If code and spec disagree, **the spec is wrong** — fix the spec to match the code in the same PR. The reverse (code changing to match spec) only happens when the spec was *intentional* and the code drifted.

Spectral CI gate enforces:
- All endpoints documented.
- All schemas valid OpenAPI 3.1.
- Examples conform to schemas.

---

## Bulk endpoint

`POST /api/v1/bulk` accepts an array of `{op, params}` items and returns an array of `{ok, data|error}` results in the same order. Operations supported are the subset that doesn't mutate (calculator-style only).

- Anonymous rate limit applies once per outer request, not per inner op.
- API key rate limit applies per outer request, but a configurable per-key multiplier scales inner-op cost.
- Max array length is bounded; documented in OpenAPI.

When adding a new calculator endpoint, decide whether bulk should support it. Default: yes. Exceptions: anything that writes (sessions, admin) — bulk is read-only.

---

## Client expectations

What consumers should do:

- **Switch on `error.code`, never `error.message`.**
- **Tolerate unknown response fields.** They mean the API added something; ignore it gracefully.
- **Respect `Retry-After` header on 429 responses.** Don't hammer.
- **Pin to `/api/v1/`** in client code, not bare `/api/`.
- **Use API keys** for any volume above casual single-request use.

What they shouldn't:

- Don't parse `error.message` strings.
- Don't assume response field ordering.
- Don't depend on undocumented response fields.
- Don't store API keys in URLs.

---

## Open questions / future work

Tracked here for visibility; not commitments:

- **API key expiry** — currently keys live forever. Adding `expires_at` to issuance + a rotation reminder is candidate for v3.8.0 if API DX becomes the theme.
- **`X-RateLimit-*` response headers** — explicitly exposing remaining quota would help well-behaved clients self-throttle.
- **Webhook surface** — not in scope. Operator-driven calculator app doesn't need event delivery. Skip unless a use case appears.
- **GraphQL** — explicitly out of scope. REST + OpenAPI fits the operator audience.
- **`Idempotency-Key` header on POST** — would let clients retry safely on network errors. Easy to add; defer until a consumer asks.

---

## Update protocol

Every PR that changes the API:
1. Update `api/openapi.yaml`.
2. Update CHANGELOG entry — note if breaking.
3. If a new `error.code` is introduced, add it to the table above.
4. If a breaking change is unavoidable in `v1`, push back: is there a non-breaking alternative? If not, escalate to a `v2` discussion.
5. If a deprecation lands, set `Deprecation`/`Sunset` headers per the policy above.
6. Bump the "Last updated" header.
