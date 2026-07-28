# Char Bazaar Service

Internal Rust service that owns character bazaar state transitions.

## Scope

- eligibility preview
- auction creation with character escrow
- proxy bidding with transferable coin reservation
- audit trail

## Run

```bash
cd services/char-bazaar
export CHAR_BAZAAR_DATABASE_URL="mysql://user:pass@127.0.0.1:3306/db"
export CHAR_BAZAAR_ESCROW_ACCOUNT_ID="2"
export CHAR_BAZAAR_INTERNAL_TOKEN="change-me"
cargo run
```

Notes:

- `CHAR_BAZAAR_INTERNAL_TOKEN` is mandatory. The service now fails to start without it.
- The default bind is `127.0.0.1:8089`. Expose it externally only behind an explicit private-network design.

## Required Database Setup

Apply:

```bash
mysql ... < services/char-bazaar/migrations/0001_char_bazaar.sql
```

## Endpoints

- `GET /healthz`
- `POST /v1/auctions/preview`
- `POST /v1/auctions`
- `GET /v1/auctions/:id`
- `POST /v1/auctions/:id/cancel`
- `POST /v1/auctions/:id/bids`
- `POST /v1/auctions/:id/settle`

All `/v1/auctions*` routes are internal-only and require:

```text
Authorization: Bearer <CHAR_BAZAAR_INTERNAL_TOKEN>
```

The `GET /v1/auctions/:id` response is intentionally redacted and no longer exposes internal winner account IDs or hidden proxy-bid limits.

Settlement also records transferable-coin ledger entries in `coins_transactions` for both the buyer and seller.
