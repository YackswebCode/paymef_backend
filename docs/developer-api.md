# PayMef Developer API (v1)

The PayMef v1 API lets a partner website or school portal read the account data a
user has authorised, without handling PayMef passwords or session tokens.

## Create a key

1. Sign in to PayMef.
2. Open **Account & Identity → Developer API**.
3. Choose **Test** for sandbox work or **Live** for real account data.
4. Copy the secret immediately — PayMef stores only a SHA-256 hash and cannot show it again.

Keys look like `pmf_test_<48 hex characters>` or `pmf_live_<48 hex characters>`.
Revoke a key from the same screen; revocation takes effect immediately.

## Authentication

Send the key as a Bearer token on every request:

```http
GET /api/v1/me HTTP/1.1
Host: 127.0.0.1:8001
Authorization: Bearer pmf_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

Call the API from your server. Never ship a secret key in browser JavaScript,
mobile app bundles or public repositories, because anyone who reads it can act
as the key owner.

## Endpoints

| Method | Path | Description |
| --- | --- | --- |
| GET | `/api/v1/me` | Account id, name, email, role and school |
| GET | `/api/v1/wallet?currency=NGN` | Wallet balance and totals in minor units |
| GET | `/api/v1/invoices?status=issued&page=1` | Invoices visible to the account |
| GET | `/api/v1/transactions?entry_type=credit&page=1` | Ledger entries visible to the account |

All responses are JSON. Lists use Laravel pagination:

```json
{
  "status": true,
  "data": {
    "current_page": 1,
    "data": [],
    "last_page": 1,
    "per_page": 20,
    "total": 0
  }
}
```

Amounts are integers in the smallest currency unit: kobo for NGN, cents for USD.

### Example: wallet

```bash
curl "http://127.0.0.1:8001/api/v1/wallet" \
  -H "Authorization: Bearer $PAYMEF_KEY" \
  -H "Accept: application/json"
```

```json
{
  "status": true,
  "data": {
    "wallet": {
      "currency": "NGN",
      "available_minor": 10100000,
      "totals": {
        "credits_minor": 10100000,
        "debits_minor": 0,
        "holds_minor": 0,
        "releases_minor": 0,
        "refunds_minor": 0
      },
      "entry_count": 2
    }
  }
}
```

### Example: invoices

```bash
curl "http://127.0.0.1:8001/api/v1/invoices?status=issued" \
  -H "Authorization: Bearer $PAYMEF_KEY"
```

### Example: Node.js

```js
const response = await fetch(`${process.env.PAYMEF_BASE_URL}/api/v1/transactions`, {
  headers: {
    Authorization: `Bearer ${process.env.PAYMEF_API_KEY}`,
    Accept: 'application/json',
  },
});

if (!response.ok) {
  throw new Error(`PayMef API ${response.status}`);
}

const { data } = await response.json();
```

## Errors

| Status | Meaning |
| --- | --- |
| 401 | Missing, malformed or revoked key |
| 404 | Resource not visible to the key owner |
| 422 | Invalid query parameter (for example a bad currency) |
| 429 | Rate limit exceeded (120 requests per minute per key) |

## Rate limits and scopes

Keys are read-only (`read` scope) and rate limited to 120 requests per minute.
Every response is scoped to the user who created the key; other accounts' data is
not addressable by id. Cached copies should be treated as a snapshot and refreshed
from the API rather than used as an authoritative ledger.
