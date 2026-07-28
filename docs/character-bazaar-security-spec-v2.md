# Character Bazaar Security Specification v2

Reviewed on: July 28, 2026
Status: Draft for implementation
Scope: AAC PHP edge, Rust auction authority, MySQL, workers, audit, moderation

## 1. Purpose

This document replaces the "minimum viable" mindset with a high-assurance design for the Character Bazaar.

The target is not "secure enough for a game feature." The target is a financial-grade, abuse-resistant state machine for:

- character custody;
- transferable coin reservation and capture;
- bid integrity;
- seller and bidder authorization;
- auditability and forensic reconstruction;
- resilience against race conditions, workflow bypass, IDOR/BOLA, CSRF, replay, and secret exposure.

This is not a claim of literal banking certification. It is an engineering target: sensitive transaction controls similar to those expected in high-value monetary workflows.

## 2. What Already Exists

The current repository already contains the following building blocks:

- Rust service at `account-service/services/char-bazaar`.
- Tables:
  - `char_bazaar_auctions`
  - `char_bazaar_bids`
  - `char_bazaar_coin_reservations`
  - `char_bazaar_audit_events`
- Implemented flows in Rust:
  - eligibility preview;
  - listing with escrow transfer by `players.account_id`;
  - proxy bid engine;
  - bid reservation movement between winners;
  - basic audit event insertion.

This means the project is not starting from zero. The core auction authority idea is already correct.

## 3. What Is Not Production-Ready Yet

The current implementation is not safe to expose or finish as-is.

### 3.1 Confirmed local gaps

- Internal authentication is optional if `CHAR_BAZAAR_INTERNAL_TOKEN` is unset.
- The Rust service binds to `0.0.0.0` by default.
- `CorsLayer::permissive()` is enabled even though the service must be backend-only.
- The hidden proxy-bid maximum leaks through API responses.
- There is no settlement flow.
- There is no cancellation flow.
- There is no end-of-auction worker.
- `active_slot` is not cleared by a completed lifecycle yet.
- Bid idempotency replays current state instead of the original response.
- AAC still has no bazaar routes, controllers, views, or hardened client integration.
- AAC CSRF enforcement does not currently protect general `/account` POST routes.
- AAC does not yet have a reusable "fresh step-up auth" challenge for listing/cancel/settle.

### 3.2 Threat classes already visible

The current shape is exposed to known vulnerability classes:

- CWE-362 race condition;
- CWE-367 TOCTOU race condition;
- CWE-639 authorization bypass through user-controlled key;
- CWE-841 improper enforcement of behavioral workflow;
- CSRF on state-changing browser routes;
- information exposure through over-detailed auction responses;
- secret exposure through weak secret handling patterns;
- broken access control if browser payloads are trusted for account identity.

## 4. External Security Basis

This specification is based on official guidance and references reviewed on July 28, 2026.

### 4.1 OWASP

- Authorization Cheat Sheet
  - Broken access control remains a top concern and authorization must be enforced in business context, not only at routing boundaries.
  - https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- Transaction Authorization Cheat Sheet
  - Sensitive transactions should require step-up authorization and protection against bypass.
  - https://cheatsheetseries.owasp.org/cheatsheets/Transaction_Authorization_Cheat_Sheet.html
- Session Management Cheat Sheet
  - Sessions should have idle timeout, absolute timeout, and server-side enforcement.
  - https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- REST Security Cheat Sheet
  - Internal APIs must require HTTPS and per-endpoint access control; privileged services should consider stronger service authentication.
  - https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html
- Input Validation Cheat Sheet
  - Validation must be both syntactic and semantic.
  - https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html
- Business Logic Security Cheat Sheet
  - Security-relevant values must be re-derived on the server, workflows must be explicit state machines, and concurrency must be treated as a real attack vector.
  - https://cheatsheetseries.owasp.org/cheatsheets/Business_Logic_Security_Cheat_Sheet.html
- SQL Injection Prevention Cheat Sheet
  - Prepared statements and least privilege remain mandatory.
  - https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html
- Database Security Cheat Sheet
  - DB isolation, least privilege, host restriction, and encrypted connections are required.
  - https://cheatsheetseries.owasp.org/cheatsheets/Database_Security_Cheat_Sheet.html
- Logging Cheat Sheet
  - Security events must be logged consistently and safely.
  - https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- Secrets Management Cheat Sheet
  - Secrets need centralized storage, rotation, and auditing.
  - https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html
- Multifactor Authentication Cheat Sheet
  - High-risk transactions should use strong second factors.
  - https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html

### 4.2 MITRE / CWE

- CWE-362: concurrent access without proper synchronization can corrupt critical state.
  - https://cwe.mitre.org/data/definitions/362.html
- CWE-367: checks are unsafe when state can change before use.
  - https://cwe.mitre.org/data/definitions/367.html
- CWE-639: authorization fails if resource keys are user-controlled and not re-checked server-side.
  - https://cwe.mitre.org/data/definitions/639.html
- CWE-841: workflow must enforce order, completeness, and timeliness of steps.
  - https://cwe.mitre.org/data/definitions/841.html

### 4.3 MySQL official documentation

- Locking reads:
  - `SELECT ... FOR UPDATE` is required when rows will be checked and then modified in the same transaction.
  - https://dev.mysql.com/doc/refman/9.7/en/innodb-locking-reads.html
- Transaction isolation:
  - lock behavior depends on index usage and isolation level.
  - https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-isolation-levels.html
- Encrypted DB connections:
  - `require_secure_transport=ON` and account-level TLS requirements are supported.
  - https://dev.mysql.com/doc/refman/9.7/en/using-encrypted-connections.html
- Least privilege:
  - MySQL roles and minimal grants are the recommended model.
  - https://dev.mysql.com/doc/mysql-secure-deployment-guide/8.0/en/secure-deployment-roles-dynamic-privileges.html

## 5. Security Objectives

The implementation must satisfy all objectives below.

### 5.1 Custody

- A listed character becomes inaccessible to the seller immediately after commit.
- No duplicate active auction can exist for the same character.
- No workflow may leave a character indefinitely trapped in escrow without recovery tooling.

### 5.2 Funds integrity

- The winning bid must always be backed by reserved transferable coins.
- Reservation, release, capture, payout, and refund must be fully auditable.
- No request sequence may debit or release funds twice.

### 5.3 Authorization

- The browser must never choose `seller_account_id`, `bidder_account_id`, or any equivalent trust anchor.
- All account identity must be derived from the authenticated AAC session.
- All resource authorization must be re-checked server-side per action.

### 5.4 Confidentiality

- Public bazaar responses must never reveal hidden proxy-bid ceilings.
- Internal tokens, DB credentials, audit secrets, and signing keys must never be exposed in source code or general logs.

### 5.5 Traceability

- Every state transition must produce an append-only audit event.
- The system must support full reconstruction of who acted, when, from where, under which session, with which idempotency key, and what changed.

## 6. Threat Model

The design must explicitly defend against:

- malicious authenticated players;
- bot-driven bidding abuse;
- replay of legitimate requests;
- browser-side tampering of hidden fields and JSON payloads;
- CSRF against logged-in accounts;
- race conditions between concurrent bids;
- race conditions between listing and gameplay/account flows;
- insider mistakes in deployment, secrets, and DB grants;
- partial failure between DB commit and HTTP response;
- settlement worker crash or duplicate execution;
- enumeration of seller, bidder, account, and auction internal data;
- forced browsing to moderation or internal endpoints.

## 7. Non-Negotiable Invariants

These invariants must be documented in code comments, tests, and runbooks.

1. A character can have at most one active auction.
2. An active auction implies the character is held by the escrow account.
3. A non-active auction must have `active_slot = NULL`.
4. The public API never trusts account IDs supplied by the browser.
5. The public API never exposes the hidden winning bid limit.
6. Reserved coins for the current winner are always greater than or equal to the hidden winning limit for that auction.
7. Settlement is atomic: capture winner funds, transfer character, clear reservation delta, close active slot, and write audit event in one transaction.
8. Idempotent retries return the original committed result, not a newly recomputed response.
9. All state-changing routes require fresh authorization context:
   - valid session;
   - CSRF for browser requests;
   - email verified;
   - step-up 2FA when enabled;
   - anti-abuse rate checks.
10. No internal route is reachable from the browser network plane.

## 8. Recommended High-Assurance Architecture

### 8.1 Logical topology

Browser -> AAC PHP -> internal bazaar client -> Rust bazaar authority -> MySQL

Separately:

- settlement worker -> Rust bazaar authority or direct shared bazaar module;
- admin moderation UI -> AAC admin -> Rust bazaar authority;
- alerting / SIEM -> audit stream.

### 8.2 Service boundaries

- Browser talks only to AAC.
- AAC owns session, CSRF, email verification, step-up auth, output encoding, public response shaping, and anti-abuse edge controls.
- Rust owns state transitions and DB transaction integrity.
- MySQL owns durable state, uniqueness, and locking guarantees.

### 8.3 Internal service authentication

The Rust service must require all of the following:

- private bind address, never `0.0.0.0` by default;
- private network or loopback bind only;
- TLS in transit;
- mTLS between AAC and Rust for service identity;
- mandatory internal bearer token as defense in depth;
- source IP allowlist or network policy restriction;
- no CORS policy that permits browsers;
- no public DNS exposure.

If `CHAR_BAZAAR_INTERNAL_TOKEN` is missing, startup must fail hard.

## 9. Public AAC Design

### 9.1 Route model

Recommended public routes:

- `GET /community/char-bazaar`
- `GET /community/char-bazaar/{auctionId}`
- `POST /account/char-bazaar/preview`
- `POST /account/char-bazaar/listings`
- `POST /account/char-bazaar/{auctionId}/cancel`
- `POST /community/char-bazaar/{auctionId}/bids`
- `GET /account/char-bazaar/my-listings`
- `GET /account/char-bazaar/my-bids`

### 9.2 Browser payload rules

The browser may send:

- `player_id`
- `auction_id`
- `bid_limit`
- display filters
- idempotency key
- step-up token reference

The browser must not send trusted values for:

- seller account id;
- bidder account id;
- email verification status;
- 2FA status;
- actor role;
- available coins;
- current price;
- winning state;
- character ownership.

Those values must be derived or recomputed by AAC and Rust.

### 9.3 CSRF

All browser state-changing bazaar routes must require CSRF protection, not only `/admin`.

Requirement:

- extend current middleware so that `POST`, `PUT`, `PATCH`, and `DELETE` for authenticated account routes validate CSRF tokens;
- reject requests missing the token with a generic error;
- log failures as security events.

### 9.4 Session and step-up rules

For bazaar-sensitive operations, the user must pass a fresh step-up challenge.

Required:

- step-up TTL: 5 minutes maximum;
- bound to actor account id, session id, action type, and target resource;
- invalid after use for listing cancellation and settlement-like operations;
- required for:
  - create listing;
  - cancel listing;
  - accept forced moderation actions that return custody;
  - optional high-value bids above configured thresholds.

Preferred authenticators:

- WebAuthn or hardware-backed factor if available;
- TOTP as minimum;
- email OTP not accepted as the only factor for high-value irreversible actions.

## 10. Rust Authority Design

### 10.1 Internal endpoints

Recommended internal namespace:

- `GET /internal/v1/healthz`
- `POST /internal/v1/auctions/preview`
- `POST /internal/v1/auctions`
- `GET /internal/v1/auctions/{auctionId}`
- `POST /internal/v1/auctions/{auctionId}/bids`
- `POST /internal/v1/auctions/{auctionId}/cancel`
- `POST /internal/v1/auctions/{auctionId}/settle`
- `POST /internal/v1/auctions/{auctionId}/force-cancel`
- `POST /internal/v1/recovery/reconcile`

Do not expose business endpoints outside `/internal`.

### 10.2 Input contract

Every mutating internal request must include:

- authenticated service identity;
- actor account id as derived by AAC;
- actor player id if relevant;
- request idempotency key;
- request timestamp;
- correlation id / trace id;
- step-up proof reference when required;
- source IP as forwarded by trusted infrastructure only.

### 10.3 Output contract

Public-safe responses must never include:

- `current_winner_bid_limit`;
- full winner identity unless policy explicitly requires it;
- internal account ids for unrelated users;
- escrow account ids;
- raw SQL or stack traces;
- secret-dependent diagnostics.

The public view should expose:

- auction id;
- character public snapshot;
- current visible price;
- time remaining;
- bid increment;
- whether the current viewer is seller or current winner, only for their own session.

## 11. Auction State Machine

The bazaar must be implemented as an explicit finite state machine.

### 11.1 States

- `draft_preview` (not persisted)
- `active`
- `ending_pending_settlement`
- `settled`
- `cancelled`
- `forced_cancelled`
- `payout_pending_review`
- `failed_recovery_hold`

### 11.2 Allowed transitions

- `draft_preview -> active`
- `active -> cancelled` only if no valid bid exists
- `active -> ending_pending_settlement` when `ends_at <= now`
- `ending_pending_settlement -> settled`
- `ending_pending_settlement -> payout_pending_review`
- `active -> forced_cancelled` by admin only
- `failed_recovery_hold -> settled | forced_cancelled` via supervised recovery

### 11.3 Forbidden transitions

- `cancelled -> active`
- `settled -> active`
- `settled -> cancelled`
- `active -> settled` without passing through end-state checks
- repeated settlement after success
- repeated forced cancel after terminal closure

## 12. Listing Eligibility Rules

### 12.1 Mandatory phase-1 checks

- player belongs to actor account;
- player offline;
- no active bazaar auction;
- not deleted;
- not pending deletion;
- not staff;
- minimum level met;
- actor email verified;
- fresh step-up proof if account has 2FA enabled;
- actor session valid and recent enough;
- character not currently in another high-risk workflow.

### 12.2 Required anti-abuse restrictions

These were optional in v1 and are mandatory in v2:

- block guild leaders;
- block characters with active guild ownership transfer implications;
- block house owners;
- block characters with active house transfer;
- block banned / name-locked / punished characters;
- block characters transferred in the last 30 days;
- block characters with unresolved moderation flags;
- block characters recently recovered after account recovery unless cooldown has elapsed.

### 12.3 Server-side truth only

Eligibility must be recomputed inside the listing transaction. Preview is advisory only.

## 13. Bid Model

### 13.1 Proxy bidding

The hidden winning limit remains internal-only.

Stored:

- `starting_bid`
- `bid_increment`
- `current_price`
- `current_winner_account_id`
- `current_winner_bid_limit`

Publicly displayed:

- `current_price`
- `bid_increment`
- `minimum_next_visible_bid`

Never displayed:

- `current_winner_bid_limit`
- unrelated winner account ids

### 13.2 Bid acceptance rules

- bidder cannot be seller;
- bidder account is derived from AAC session;
- bidder must own the selected bidder character if the design requires a bidder avatar;
- `bid_limit` must be within configured numeric bounds;
- bidder must have enough spendable transferable coins after subtracting all active reservations except their own current hold on the same auction;
- bid must be rejected if auction is not `active`;
- bid must be rejected if `ends_at <= now`;
- all rules rechecked under lock in the same transaction.

### 13.3 Idempotency

For every mutating route:

- persist request id, canonical request hash, response body hash, and final response payload;
- if the same idempotency key is replayed with the same canonical payload, return the original stored response;
- if the same idempotency key is replayed with a different canonical payload, return conflict and log abuse.

## 14. Database Security and Data Model

### 14.1 Database network posture

Required:

- database not internet-exposed;
- bind to private interfaces only;
- firewall restricted to AAC, Rust service, workers, and admin bastion hosts;
- no direct browser or thick-client connection;
- `require_secure_transport=ON`;
- app clients use TLS and verify server identity;
- MySQL application users configured with encrypted connection requirements, preferably `REQUIRE X509`.

### 14.2 Database credentials

Required:

- never committed to source code;
- never rendered by `phpinfo`;
- stored in secret manager or encrypted runtime config;
- rotated on schedule and on incident;
- unique account per service role;
- no credential sharing between AAC web edge, Rust writer, worker, or admin tooling.

### 14.3 Least-privilege DB accounts

Create separate DB identities:

- `bazaar_aac_read`
  - read-only for public bazaar views and viewer-specific dashboards;
- `bazaar_writer`
  - minimal `SELECT`, `INSERT`, `UPDATE` on bazaar tables and required game tables for auction transitions;
- `bazaar_settlement_worker`
  - same or narrower write scope than writer, only for end-state processing;
- `bazaar_admin_moderation`
  - explicit additional rights for force-cancel and recovery operations;
- no app account may have global admin privileges.

### 14.4 Schema requirements

The schema must enforce, not merely suggest, core invariants.

Required fields:

- `char_bazaar_auctions`
  - `status`
  - `active_slot`
  - `version`
  - `ended_at`
  - `settled_at`
  - `cancelled_at`
  - `closed_reason`
  - `step_up_proof_id`
  - `public_snapshot_json`
  - `private_snapshot_json`
- `char_bazaar_bids`
  - `canonical_request_hash`
  - `response_payload_json`
  - `response_payload_hash`
- `char_bazaar_coin_reservations`
  - aggregate row per account;
- `char_bazaar_audit_events`
  - append-only;
  - `prev_event_hash`;
  - `event_hash`.

Recommended constraints:

- unique `(player_id, active_slot)` with `active_slot = 1` only while active;
- unique request ids for listing, bidding, cancel, settle, force-cancel;
- foreign keys on all actor and auction references;
- check-like validation in app and migration comments for numeric bounds;
- explicit covering indexes for point-lock lookups.

### 14.5 Locking and transaction policy

All critical transitions must run in one transaction.

Required lock order:

1. auction row or player row primary subject;
2. actor account rows;
3. reservation rows;
4. escrow account row;
5. related moderation/recovery rows if any.

This fixed lock order is mandatory to reduce deadlock risk.

Required protections:

- point lookups using indexed predicates;
- `SELECT ... FOR UPDATE` before using mutable state;
- unique constraints as final line of defense;
- duplicate-key and deadlock handling with bounded retries;
- no split "check in one request, act in another" for sensitive transitions.

### 14.6 Isolation guidance

Preferred approach:

- use `READ COMMITTED` or the chosen project standard consistently;
- rely on explicit locking reads plus unique constraints for correctness;
- document the exact isolation choice in code and runbooks;
- ensure every critical lookup uses indexed predicates to avoid oversized lock ranges.

Inference from MySQL docs: correctness depends less on the isolation label alone and more on whether lock-protected point reads and uniqueness constraints are used correctly.

## 15. Settlement and Recovery

### 15.1 Settlement worker

Settlement must be worker-driven, not browser-driven.

Process:

1. select ended active auctions in small batches;
2. lock auction row;
3. re-derive terminal state;
4. lock winner and seller accounts;
5. lock reservation rows;
6. verify reservation still covers winner obligation;
7. capture winner coins atomically;
8. transfer character from escrow to winner;
9. release or adjust reservation;
10. close `active_slot`;
11. set `status = settled` or `payout_pending_review`;
12. write audit events;
13. commit.

### 15.2 Failure handling

If settlement fails after retries:

- mark `failed_recovery_hold`;
- keep character in escrow;
- keep reservations consistent;
- raise alert;
- require operator-assisted recovery playbook.

### 15.3 Forced cancellation

Admin forced cancellation must:

- require strong admin auth;
- require dual-control approval for high-value auctions if possible;
- record reason code;
- release holds safely;
- restore character custody if policy says so;
- create distinct audit events.

## 16. Audit, Logging, and Forensics

### 16.1 Application audit events

Log before and after all security-sensitive actions:

- preview requested;
- preview denied;
- listing created;
- listing rejected;
- bid accepted;
- bid rejected;
- listing cancelled;
- force-cancelled;
- settlement succeeded;
- settlement retried;
- settlement failed to hold state;
- step-up auth challenged;
- step-up auth passed;
- step-up auth failed;
- token validation failed;
- idempotency replay;
- idempotency payload mismatch;
- suspicious rate-limit hit.

### 16.2 Required fields

Each audit event must include:

- event id;
- timestamp UTC;
- trace id;
- actor account id;
- actor player id if relevant;
- session id or session fingerprint;
- source IP;
- user agent hash or device fingerprint where lawful;
- auction id;
- request id;
- action;
- result;
- reason code;
- before state hash;
- after state hash;
- previous audit event hash;
- event hash.

### 16.3 Tamper evidence

Audit rows should be append-only and hash-chained.

Goal:

- detect row deletion or mutation after the fact;
- support forensic review of disputed auctions.

## 17. Known Bug Patterns This Design Must Prevent

This section translates known vulnerability classes into bazaar-specific requirements.

### 17.1 IDOR / BOLA / CWE-639

Bad pattern:

- browser sends `seller_account_id` or `bidder_account_id`;
- backend trusts that id;
- attacker modifies the id and acts for another account.

Required prevention:

- derive actor from session;
- bind action to session account;
- re-check ownership on every row access.

### 17.2 Race condition / CWE-362

Bad pattern:

- check balance;
- later reserve or debit in another operation.

Required prevention:

- lock then compute then write in one transaction;
- fixed lock order;
- unique request ids;
- deadlock retry discipline.

### 17.3 TOCTOU / CWE-367

Bad pattern:

- preview says eligible;
- later listing trusts old preview result.

Required prevention:

- preview is informational only;
- listing recomputes everything under lock.

### 17.4 Workflow bypass / CWE-841

Bad pattern:

- user skips step-up challenge;
- cancel occurs after a bid exists;
- settlement runs twice;
- worker and admin act in parallel without a state machine.

Required prevention:

- explicit state machine;
- single transition owner;
- terminal-state checks;
- step-up proof bound to action and TTL.

### 17.5 Information leakage

Bad pattern:

- exposing hidden max bid;
- exposing internal account ids;
- verbose errors that reveal why auth failed in too much detail.

Required prevention:

- public response redaction;
- generic user-facing errors;
- detailed diagnostics only in internal logs.

### 17.6 Secret exposure

Bad pattern:

- hard-coded internal token;
- token omitted and service still starts;
- secrets stored in repo or web root.

Required prevention:

- mandatory secret presence on startup;
- secret manager;
- rotation;
- separate credentials per service.

## 18. Testing Matrix

The feature is not launchable until all tests below exist and pass.

### 18.1 Unit tests

- proxy bid engine;
- reservation arithmetic;
- state machine transition guards;
- redaction of public responses;
- idempotency payload matching.

### 18.2 Integration tests

- concurrent listing of same character;
- concurrent bids in same millisecond;
- same bidder retry with same idempotency key;
- same idempotency key with different payload;
- seller trying to bid;
- listing by non-owner;
- settlement after winner spend elsewhere;
- forced cancellation with active reservation;
- worker retry after crash between commit and response;
- audit chain continuity.

### 18.3 Security tests

- CSRF against all browser POST routes;
- IDOR tampering on account and auction identifiers;
- direct browser attempt to call Rust internal routes;
- replay of old step-up proof;
- session fixation / session reuse after timeout;
- bid automation and rate-limit validation;
- response leakage checks for hidden max bid;
- secret misconfiguration should fail startup.

### 18.4 DB and chaos tests

- deadlock simulation;
- DB failover during settlement;
- partial network loss between AAC and Rust;
- duplicate worker execution;
- orphan escrow recovery;
- audit write failure handling;
- clock skew tolerance between AAC and Rust.

## 19. Rollout Gates

### 19.1 Gate 0: design approval

- spec approved;
- state machine approved;
- moderation and recovery playbooks approved.

### 19.2 Gate 1: internal hardening

- internal auth mandatory;
- mTLS in place;
- CORS removed;
- private bind enforced;
- DB TLS enforced;
- least-privilege DB users created.

### 19.3 Gate 2: lifecycle completeness

- cancel flow done;
- settlement flow done;
- recovery flow done;
- audit chain done;
- public data redaction done.

### 19.4 Gate 3: AAC edge controls

- routes/controllers/views done;
- CSRF fixed for account routes;
- step-up auth flow done;
- email verification enforcement done;
- anti-abuse rate limiting done.

### 19.5 Gate 4: test and observability

- full matrix automated;
- alerts connected;
- dashboards ready;
- manual runbooks validated.

## 20. Implementation Priorities

Priority 0:

- fail startup if internal token missing;
- remove permissive CORS;
- bind privately by default;
- stop leaking `current_winner_bid_limit`;
- add public/internal response split.

Priority 1:

- implement full state machine;
- implement cancel, settle, force-cancel, recovery;
- persist exact idempotent response payloads;
- build worker.

Priority 2:

- fix AAC CSRF for non-admin authenticated POST routes;
- add bazaar controllers/routes/views;
- derive account identity from session only;
- add fresh step-up auth.

Priority 3:

- implement DB TLS, MySQL roles, secret rotation, hash-chained audit;
- add fraud controls and anomaly alerts.

## 21. Final Recommendation

The current codebase already has the correct architectural seed: a Rust transaction authority with escrow and reservation concepts.

However, the production version must be treated as a regulated-style transaction system, not as a normal web feature. The safe path is:

1. harden the internal service boundary first;
2. complete the state machine second;
3. add AAC edge protections third;
4. only then expose public bazaar routes.

If any of the following remain unresolved, launch must be blocked:

- optional internal auth;
- public leakage of proxy-bid max;
- missing settlement/recovery;
- missing CSRF on AAC bazaar routes;
- browser-controlled account identity;
- incomplete test matrix.
