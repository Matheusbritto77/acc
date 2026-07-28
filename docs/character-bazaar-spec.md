# Character Bazaar Spec

## Goal

Implement a secure character bazaar without depending on a gameplay patch in the C++ server for the initial rollout.

The first production cut should:

- lock the listed character out of the seller account immediately;
- keep the auction authority outside PHP request state;
- use MySQL transactions and row locks for every state transition;
- reserve transferable coins for the current winning bid;
- leave a full audit trail for every mutation.

## External References

This design follows the public behavior and security guidance below:

- Tibia Character Trade: <https://www.tibia.com/charactertrade/?subtopic=currentcharactertrades>
- OWASP Authorization Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html>
- OWASP Input Validation Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html>
- OWASP CSRF Prevention Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html>
- OWASP Transaction Authorization Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html>

## Why Rust

The current monorepo has no Rust service yet, but a Rust auction authority is the safest way to avoid pushing new transactional logic into PHP controllers or into the game loop.

The C++ game server does not need to know about the bazaar in phase 1 because the listed character is moved to an escrow account in the database. Since login character lists are account based, the seller cannot access the listed character while the auction is active.

## Current Schema Constraints

Existing tables already provide the minimum primitives we need:

- `players.account_id`: ownership transfer pivot.
- `players_online.player_id`: online check before listing and settlement.
- `accounts.coins_transferable`: transferable coin balance for bids.
- `account_sessions.id/account_id/expires`: web session validation on the PHP edge.
- `player_items`, `player_depotitems`, `player_inboxitems`, `player_storage`: all remain attached to `player_id`, so escrow transfer does not move inventory rows.

## Security Model

## Edge Authentication

The public website must never call the Rust service directly from the browser.

The required flow is:

1. AAC authenticates the seller with its normal session.
2. AAC enforces CSRF validation.
3. AAC enforces email verification.
4. AAC enforces authenticator verification for listing, cancellation, and settlement-sensitive actions when 2FA is enabled on the account.
5. AAC calls the Rust service over a private network with an internal bearer token.

The Rust service trusts only the AAC backend, never a browser payload.

## Transaction Safety

Every mutating operation must run inside a single MySQL transaction and lock rows with `SELECT ... FOR UPDATE`.

Required locked rows by operation:

- `create auction`: `players`, `accounts` seller, active auction probe, escrow account.
- `place bid`: auction row, bidder account row, bidder reservation row, previous winner reservation row if applicable.
- `cancel auction`: auction row, player row, escrow account row.
- `settle auction`: auction row, winner account row, seller account row, player row, reservation rows.

## Anti-Abuse Rules

- All write operations require an idempotency key.
- One active auction per character.
- Seller cannot bid on own auction.
- Character must be offline to list, cancel, or settle.
- Character must not be scheduled for deletion.
- Staff characters are blocked by default.
- Listing from an account without email verification is blocked.
- Listing from an account with 2FA enabled requires a fresh TOTP challenge on the AAC edge.
- Bid requests are rate limited per account and IP on the AAC edge.
- Winning bid uses reserved transferable coins, not a best-effort balance check.

## Functional Rules

## Listing Eligibility

Character can be listed only when:

- `players.account_id == seller_account_id`
- character is offline
- character has no active bazaar auction
- character is not deleted / pending deletion
- `group_id == 1`
- level is at least the configured minimum

Recommended phase 1 restrictions:

- block guild leaders;
- block house owners or characters with active house transfer;
- block characters with unresolved name lock or ban state;
- block characters that received ownership in the last 30 days.

## Auction Model

Phase 1 uses proxy bidding with a hidden `bid_limit`.

Stored values:

- `starting_bid`
- `bid_increment`
- `current_price`
- `current_winner_account_id`
- `current_winner_bid_limit`

Proxy rules:

- first valid bid becomes winner and sets `current_price = starting_bid`;
- if a new bid does not exceed the current winner max, current winner remains and price rises only to `min(current_winner_bid_limit, new_bid + increment)`;
- if a new bid exceeds the current winner max, winner changes and price becomes `min(new_bid, old_winner_max + increment)`.

## Ownership Locking

On auction creation:

1. service locks the player row;
2. service validates ownership and eligibility;
3. service moves `players.account_id` to the escrow account;
4. service creates the auction row and audit event;
5. service commits.

This is the key control that avoids a C++ login patch in phase 1.

## Settlement

On successful settlement:

1. ensure auction is ended and still active for settlement;
2. ensure winner still has the reserved coins;
3. decrement winner transferable coins;
4. release reservation;
5. transfer character from escrow account to winner account;
6. optionally credit seller coins, or mark payout pending if you want a manual review gate;
7. clear auction active slot;
8. write audit event.

## Cancellation

Auction can be cancelled only when there are no valid bids yet.

If there is already a winning bid, cancellation must be blocked unless an admin-only forced cancellation path is used. Forced cancellation must generate a distinct audit event.

## Data Model

The Rust service migration introduces:

- `char_bazaar_auctions`
- `char_bazaar_bids`
- `char_bazaar_coin_reservations`
- `char_bazaar_audit_events`

Important design choices:

- `active_slot = 1` while the auction is live; `NULL` once closed. This gives a unique active-auction guard per character.
- `create_request_id` and `bids.request_id` are unique for idempotency.
- reservations are tracked outside `accounts` to avoid invasive schema changes.

## Service API

Initial internal API:

- `GET /healthz`
- `POST /v1/auctions/preview`
- `POST /v1/auctions`
- `GET /v1/auctions/:id`
- `POST /v1/auctions/:id/bids`

All `POST` routes are internal-only and must require `Authorization: Bearer <CHAR_BAZAAR_INTERNAL_TOKEN>`.

## Rollout Plan

## Phase 1

- deploy Rust service behind private network;
- call it only from AAC backend;
- support listing, bidding, viewing, settlement worker, and cancellation before first bid;
- keep game-server C++ unchanged.

## Phase 2

- public AAC pages for bazaar;
- seller/winner notifications by email;
- scheduled settlement worker;
- admin moderation panel;
- guild leader / house / punishment cross-checks.

## Phase 3

- optional client integration;
- optional in-game read-only bazaar feed;
- optional analytics and fraud scoring.

## Test Matrix

Mandatory tests before production:

- concurrent listing attempts for the same character;
- concurrent bids from two accounts in the same millisecond;
- duplicate HTTP retry with the same idempotency key;
- seller trying to log in during active auction;
- winner losing transferable coins in another flow between bid and settlement;
- escrow recovery after crash between update and response;
- forced cancellation and hold release.
