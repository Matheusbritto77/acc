mod auction_engine;
mod config;
mod error;
mod models;
mod state;

use std::net::SocketAddr;

use auction_engine::{AuctionBidState, apply_bid};
use axum::{
    Json, Router,
    extract::{Path, State},
    http::{HeaderMap, StatusCode},
    routing::{get, post},
};
use chrono::{DateTime, Duration, Utc};
use config::Config;
use error::AppError;
use models::{
    AuctionCreatedResponse, AuctionStateChangeResponse, AuctionViewResponse, CancelAuctionRequest,
    CreateAuctionRequest, EligibilityPreviewRequest, EligibilityPreviewResponse, HealthResponse,
    PlaceBidRequest, PlaceBidResponse, SettleAuctionRequest, WatchlistAuctionResponse,
    WatchlistMutationRequest, WatchlistMutationResponse,
};
use sqlx::{
    migrate::Migrator,
    MySqlPool, Row,
    mysql::{MySqlPoolOptions, MySqlRow},
};
use state::AppState;
use tower_http::trace::TraceLayer;
use tracing::info;
use uuid::Uuid;

static MIGRATOR: Migrator = sqlx::migrate!("./migrations");

const COIN_TRANSACTION_TYPE_ADD: u8 = 1;
const COIN_TRANSACTION_TYPE_REMOVE: u8 = 2;
const COIN_TYPE_TRANSFERABLE: u8 = 3;
const MAX_ACCOUNT_COINS: u64 = u32::MAX as u64;

struct AccountCoinBalances {
    coins: u64,
    coins_transferable: u64,
}

#[tokio::main]
async fn main() -> Result<(), AppError> {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env()
                .unwrap_or_else(|_| "char_bazaar_service=info,tower_http=info".into()),
        )
        .init();

    let config = Config::from_env()?;
    let pool = MySqlPoolOptions::new()
        .max_connections(10)
        .connect(&config.database_url)
        .await?;
    MIGRATOR
        .run(&pool)
        .await
        .map_err(|error| AppError::config(format!("failed to run bazaar migrations: {error}")))?;

    let state = AppState { pool, config };
    let app = Router::new()
        .route("/healthz", get(healthz))
        .route("/v1/auctions/preview", post(preview_eligibility))
        .route("/v1/auctions", post(create_auction))
        .route("/v1/auctions/{auction_id}", get(get_auction))
        .route("/v1/accounts/{account_id}/watchlist", get(get_watchlist))
        .route("/v1/auctions/{auction_id}/watch", post(watch_auction))
        .route("/v1/auctions/{auction_id}/unwatch", post(unwatch_auction))
        .route("/v1/auctions/{auction_id}/cancel", post(cancel_auction))
        .route("/v1/auctions/{auction_id}/bids", post(place_bid))
        .route("/v1/auctions/{auction_id}/settle", post(settle_auction))
        .layer(TraceLayer::new_for_http())
        .with_state(state.clone());

    let address: SocketAddr = state
        .config
        .bind
        .parse()
        .map_err(|_| AppError::config("invalid CHAR_BAZAAR_BIND"))?;

    info!("char bazaar service listening on {}", address);
    let listener = tokio::net::TcpListener::bind(address)
        .await
        .map_err(|error| AppError::config(format!("failed to bind listener: {error}")))?;

    axum::serve(listener, app)
        .with_graceful_shutdown(shutdown_signal())
        .await
        .map_err(|error| AppError::config(format!("server error: {error}")))?;

    Ok(())
}

async fn shutdown_signal() {
    let ctrl_c = async {
        let _ = tokio::signal::ctrl_c().await;
    };

    #[cfg(unix)]
    let terminate = async {
        use tokio::signal::unix::{SignalKind, signal};
        if let Ok(mut signal) = signal(SignalKind::terminate()) {
            let _ = signal.recv().await;
        }
    };

    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => {},
        _ = terminate => {},
    }
}

async fn healthz() -> Json<HealthResponse> {
    Json(HealthResponse { status: "ok" })
}

async fn preview_eligibility(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(request): Json<EligibilityPreviewRequest>,
) -> Result<Json<EligibilityPreviewResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;
    let preview = load_eligibility_preview(
        &state.pool,
        state.config.min_level,
        request.seller_account_id,
        request.player_id,
    )
    .await?;
    Ok(Json(preview))
}

async fn create_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(request): Json<CreateAuctionRequest>,
) -> Result<(StatusCode, Json<AuctionCreatedResponse>), AppError> {
    authorize_internal(&headers, &state.config)?;

    if request.starting_bid == 0 {
        return Err(AppError::bad_request(
            "starting bid must be greater than zero",
        ));
    }

    let duration = validate_duration(request.duration_hours)?;
    let bid_increment = request.bid_increment.unwrap_or(1).max(1);
    let ends_at = Utc::now() + duration;

    let mut tx = state.pool.begin().await?;

    if let Some(existing_id) =
        fetch_existing_auction_by_request(&mut tx, request.request_id).await?
    {
        let auction = fetch_auction(&state.pool, existing_id).await?;
        tx.commit().await?;
        return Ok((
            StatusCode::OK,
            Json(AuctionCreatedResponse {
                auction_id: auction.auction_id,
                player_id: auction.player_id,
                ends_at: auction.ends_at,
                status: "active",
            }),
        ));
    }

    let player = sqlx::query(
        r#"
        SELECT p.id, p.name, p.account_id, p.level, p.vocation, p.looktype, p.deletion, p.group_id
        FROM players p
        WHERE p.id = ?
        FOR UPDATE
        "#,
    )
    .bind(request.player_id)
    .fetch_optional(&mut *tx)
    .await?
    .ok_or_else(|| AppError::not_found("character not found"))?;

    let player_account_id = player.get::<u32, _>("account_id");
    if player_account_id != request.seller_account_id {
        return Err(AppError::unauthorized(
            "character does not belong to the seller account",
        ));
    }

    if player.get::<u64, _>("deletion") != 0 {
        return Err(AppError::conflict("character is scheduled for deletion"));
    }

    if player.get::<u32, _>("group_id") != 1 {
        return Err(AppError::conflict("staff characters cannot be listed"));
    }

    if player.get::<u32, _>("level") < state.config.min_level {
        return Err(AppError::conflict(format!(
            "character level must be at least {}",
            state.config.min_level
        )));
    }

    ensure_player_offline(&mut tx, request.player_id).await?;
    ensure_no_active_auction(&mut tx, request.player_id).await?;
    ensure_account_exists_and_lock(&mut tx, request.seller_account_id).await?;
    lock_account_exists(&mut tx, state.config.escrow_account_id).await?;

    sqlx::query(
        r#"
        UPDATE players
        SET account_id = ?
        WHERE id = ? AND account_id = ?
        "#,
    )
    .bind(state.config.escrow_account_id)
    .bind(request.player_id)
    .bind(request.seller_account_id)
    .execute(&mut *tx)
    .await?;

    let auction_id = sqlx::query(
        r#"
        INSERT INTO char_bazaar_auctions (
            player_id,
            seller_account_id,
            seller_name_snapshot,
            level_snapshot,
            vocation_snapshot,
            looktype_snapshot,
            escrow_account_id,
            create_request_id,
            starting_bid,
            bid_increment,
            current_price,
            ends_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        "#,
    )
    .bind(request.player_id)
    .bind(request.seller_account_id)
    .bind(player.get::<String, _>("name"))
    .bind(player.get::<u32, _>("level"))
    .bind(player.get::<u32, _>("vocation"))
    .bind(player.get::<u32, _>("looktype"))
    .bind(state.config.escrow_account_id)
    .bind(request.request_id.to_string())
    .bind(request.starting_bid)
    .bind(bid_increment)
    .bind(0_u64)
    .bind(ends_at.naive_utc())
    .execute(&mut *tx)
    .await?
    .last_insert_id();

    insert_audit_event(
        &mut tx,
        auction_id,
        "auction_created",
        Some(request.seller_account_id),
        Some(request.player_id),
        Some(request.request_id),
        serde_json::json!({
            "starting_bid": request.starting_bid,
            "bid_increment": bid_increment,
            "duration_hours": request.duration_hours
        }),
    )
    .await?;

    tx.commit().await?;

    Ok((
        StatusCode::CREATED,
        Json(AuctionCreatedResponse {
            auction_id,
            player_id: request.player_id,
            ends_at,
            status: "active",
        }),
    ))
}

async fn get_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
) -> Result<Json<AuctionViewResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;
    let auction = fetch_auction(&state.pool, auction_id).await?;
    Ok(Json(auction))
}

async fn get_watchlist(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(account_id): Path<u32>,
) -> Result<Json<Vec<WatchlistAuctionResponse>>, AppError> {
    authorize_internal(&headers, &state.config)?;
    let watchlist = fetch_watchlist(&state.pool, account_id).await?;
    Ok(Json(watchlist))
}

async fn watch_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
    Json(request): Json<WatchlistMutationRequest>,
) -> Result<Json<WatchlistMutationResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;

    let mut tx = state.pool.begin().await?;

    if let Some(existing) = fetch_watchlist_by_request(&mut tx, request.request_id, auction_id).await? {
        tx.commit().await?;
        return Ok(Json(existing));
    }

    ensure_account_exists_and_lock(&mut tx, request.account_id).await?;

    let auction_row = sqlx::query(
        r#"
        SELECT id, seller_account_id
        FROM char_bazaar_auctions
        WHERE id = ?
        FOR UPDATE
        "#,
    )
    .bind(auction_id)
    .fetch_optional(&mut *tx)
    .await?
    .ok_or_else(|| AppError::not_found("auction not found"))?;

    if auction_row.get::<u32, _>("seller_account_id") == request.account_id {
        return Err(AppError::conflict("seller cannot watch own auction"));
    }

    sqlx::query(
        r#"
        INSERT INTO char_bazaar_watchlist (account_id, auction_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE watched_at = CURRENT_TIMESTAMP
        "#,
    )
    .bind(request.account_id)
    .bind(auction_id)
    .execute(&mut *tx)
    .await?;

    insert_audit_event(
        &mut tx,
        auction_id,
        "auction_watched",
        Some(request.account_id),
        None,
        Some(request.request_id),
        serde_json::json!({
            "account_id": request.account_id,
            "watched": true
        }),
    )
    .await?;

    tx.commit().await?;

    Ok(Json(WatchlistMutationResponse {
        auction_id,
        watched: true,
    }))
}

async fn unwatch_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
    Json(request): Json<WatchlistMutationRequest>,
) -> Result<Json<WatchlistMutationResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;

    let mut tx = state.pool.begin().await?;

    if let Some(existing) = fetch_watchlist_by_request(&mut tx, request.request_id, auction_id).await? {
        tx.commit().await?;
        return Ok(Json(existing));
    }

    ensure_account_exists_and_lock(&mut tx, request.account_id).await?;

    sqlx::query(
        r#"
        SELECT id
        FROM char_bazaar_auctions
        WHERE id = ?
        FOR UPDATE
        "#,
    )
    .bind(auction_id)
    .fetch_optional(&mut *tx)
    .await?
    .ok_or_else(|| AppError::not_found("auction not found"))?;

    sqlx::query(
        r#"
        DELETE FROM char_bazaar_watchlist
        WHERE account_id = ? AND auction_id = ?
        "#,
    )
    .bind(request.account_id)
    .bind(auction_id)
    .execute(&mut *tx)
    .await?;

    insert_audit_event(
        &mut tx,
        auction_id,
        "auction_unwatched",
        Some(request.account_id),
        None,
        Some(request.request_id),
        serde_json::json!({
            "account_id": request.account_id,
            "watched": false
        }),
    )
    .await?;

    tx.commit().await?;

    Ok(Json(WatchlistMutationResponse {
        auction_id,
        watched: false,
    }))
}

async fn place_bid(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
    Json(request): Json<PlaceBidRequest>,
) -> Result<Json<PlaceBidResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;

    let mut tx = state.pool.begin().await?;

    if let Some(existing) = fetch_bid_by_request(&mut tx, request.request_id).await? {
        tx.commit().await?;
        return Ok(Json(existing));
    }

    let auction_row = sqlx::query(
        r#"
        SELECT
            id,
            player_id,
            seller_account_id,
            status,
            starting_bid,
            bid_increment,
            current_price,
            current_winner_account_id,
            current_winner_player_id,
            current_winner_bid_limit,
            ends_at
        FROM char_bazaar_auctions
        WHERE id = ?
        FOR UPDATE
        "#,
    )
    .bind(auction_id)
    .fetch_optional(&mut *tx)
    .await?
    .ok_or_else(|| AppError::not_found("auction not found"))?;

    let status = auction_row.get::<String, _>("status");
    if status != "active" {
        return Err(AppError::conflict("auction is not active"));
    }

    let ends_at = auction_row
        .get::<chrono::NaiveDateTime, _>("ends_at")
        .and_utc();
    if ends_at <= Utc::now() {
        return Err(AppError::conflict("auction already ended"));
    }

    let seller_account_id = auction_row.get::<u32, _>("seller_account_id");
    if seller_account_id == request.bidder_account_id {
        return Err(AppError::conflict("seller cannot bid on own auction"));
    }

    ensure_player_belongs_to_account(&mut tx, request.bidder_player_id, request.bidder_account_id)
        .await?;
    ensure_account_exists_and_lock(&mut tx, request.bidder_account_id).await?;
    ensure_reservation_row(&mut tx, request.bidder_account_id).await?;

    let bidder_available = load_spendable_coins(
        &mut tx,
        request.bidder_account_id,
        auction_row.get::<Option<u32>, _>("current_winner_account_id"),
        auction_row.get::<Option<u64>, _>("current_winner_bid_limit"),
    )
    .await?;
    if bidder_available < request.bid_limit {
        return Err(AppError::conflict(
            "insufficient transferable coins for this bid limit",
        ));
    }

    let decision = apply_bid(
        AuctionBidState {
            starting_bid: auction_row.get::<u64, _>("starting_bid"),
            bid_increment: auction_row.get::<u64, _>("bid_increment"),
            current_price: auction_row.get::<u64, _>("current_price"),
            current_winner_account_id: auction_row
                .get::<Option<u32>, _>("current_winner_account_id"),
            current_winner_bid_limit: auction_row.get::<Option<u64>, _>("current_winner_bid_limit"),
        },
        request.bidder_account_id,
        request.bid_limit,
    )?;

    if decision.bidder_is_winner {
        shift_winning_reservation(
            &mut tx,
            request.bidder_account_id,
            auction_row.get::<Option<u32>, _>("current_winner_account_id"),
            auction_row.get::<Option<u64>, _>("current_winner_bid_limit"),
            decision.next_winner_bid_limit,
        )
        .await?;
    }

    sqlx::query(
        r#"
        UPDATE char_bazaar_auctions
        SET current_price = ?,
            current_winner_account_id = ?,
            current_winner_player_id = ?,
            current_winner_bid_limit = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        "#,
    )
    .bind(decision.next_price)
    .bind(decision.next_winner_account_id)
    .bind(if decision.bidder_is_winner {
        Some(request.bidder_player_id)
    } else {
        auction_row.get::<Option<u32>, _>("current_winner_player_id")
    })
    .bind(decision.next_winner_bid_limit)
    .bind(auction_id)
    .execute(&mut *tx)
    .await?;

    sqlx::query(
        r#"
        INSERT INTO char_bazaar_bids (
            auction_id,
            bidder_account_id,
            bidder_player_id,
            request_id,
            bid_limit,
            price_after_bid,
            became_winner
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
        "#,
    )
    .bind(auction_id)
    .bind(request.bidder_account_id)
    .bind(request.bidder_player_id)
    .bind(request.request_id.to_string())
    .bind(request.bid_limit)
    .bind(decision.next_price)
    .bind(decision.bidder_is_winner)
    .execute(&mut *tx)
    .await?;

    insert_audit_event(
        &mut tx,
        auction_id,
        "bid_placed",
        Some(request.bidder_account_id),
        Some(request.bidder_player_id),
        Some(request.request_id),
        serde_json::json!({
            "bid_limit": request.bid_limit,
            "price_after_bid": decision.next_price,
            "bidder_is_winner": decision.bidder_is_winner
        }),
    )
    .await?;

    tx.commit().await?;

    Ok(Json(PlaceBidResponse {
        auction_id,
        current_price: decision.next_price,
        bidder_is_winner: decision.bidder_is_winner,
    }))
}

async fn cancel_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
    Json(request): Json<CancelAuctionRequest>,
) -> Result<Json<AuctionStateChangeResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;

    let mut tx = state.pool.begin().await?;

    if let Some(existing) =
        fetch_state_change_by_request(&mut tx, request.request_id, auction_id).await?
    {
        tx.commit().await?;
        return Ok(Json(existing));
    }

    let auction_row = fetch_auction_row_for_update(&mut tx, auction_id).await?;
    let status = auction_row.get::<String, _>("status");
    if status == "cancelled" {
        let response = auction_state_change_response_from_row(&auction_row);
        tx.commit().await?;
        return Ok(Json(response));
    }

    if status != "active" {
        return Err(AppError::conflict(
            "auction cannot be cancelled in the current state",
        ));
    }

    let seller_account_id = auction_row.get::<u32, _>("seller_account_id");
    if seller_account_id != request.seller_account_id {
        return Err(AppError::unauthorized(
            "only the seller account can cancel this auction",
        ));
    }

    if auction_row
        .get::<Option<u32>, _>("current_winner_account_id")
        .is_some()
    {
        return Err(AppError::conflict("auction with bids cannot be cancelled"));
    }

    let player_id = auction_row.get::<u32, _>("player_id");
    let escrow_account_id = auction_row.get::<u32, _>("escrow_account_id");

    ensure_player_offline(&mut tx, player_id).await?;
    ensure_player_in_escrow(&mut tx, player_id, escrow_account_id).await?;
    lock_accounts_in_order(&mut tx, &[seller_account_id, escrow_account_id]).await?;
    transfer_player_to_account(&mut tx, player_id, escrow_account_id, seller_account_id).await?;

    sqlx::query(
        r#"
        UPDATE char_bazaar_auctions
        SET status = 'cancelled',
            active_slot = NULL,
            cancelled_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        "#,
    )
    .bind(auction_id)
    .execute(&mut *tx)
    .await?;

    insert_audit_event(
        &mut tx,
        auction_id,
        "auction_cancelled",
        Some(seller_account_id),
        Some(player_id),
        Some(request.request_id),
        serde_json::json!({
            "seller_account_id": seller_account_id
        }),
    )
    .await?;

    tx.commit().await?;

    Ok(Json(AuctionStateChangeResponse {
        auction_id,
        player_id,
        status: "cancelled".to_string(),
        current_price: auction_row.get::<u64, _>("current_price"),
        has_winner: false,
    }))
}

async fn settle_auction(
    State(state): State<AppState>,
    headers: HeaderMap,
    Path(auction_id): Path<u64>,
    Json(request): Json<SettleAuctionRequest>,
) -> Result<Json<AuctionStateChangeResponse>, AppError> {
    authorize_internal(&headers, &state.config)?;

    let mut tx = state.pool.begin().await?;

    if let Some(existing) =
        fetch_state_change_by_request(&mut tx, request.request_id, auction_id).await?
    {
        tx.commit().await?;
        return Ok(Json(existing));
    }

    let auction_row = fetch_auction_row_for_update(&mut tx, auction_id).await?;
    let status = auction_row.get::<String, _>("status");
    if status == "settled" || status == "ended" {
        let response = auction_state_change_response_from_row(&auction_row);
        tx.commit().await?;
        return Ok(Json(response));
    }

    if status == "cancelled" {
        return Err(AppError::conflict("cancelled auction cannot be settled"));
    }

    if status != "active" {
        return Err(AppError::conflict(
            "auction cannot be settled in the current state",
        ));
    }

    let ends_at = auction_row
        .get::<chrono::NaiveDateTime, _>("ends_at")
        .and_utc();
    if ends_at > Utc::now() {
        return Err(AppError::conflict("auction has not ended yet"));
    }

    let player_id = auction_row.get::<u32, _>("player_id");
    let seller_account_id = auction_row.get::<u32, _>("seller_account_id");
    let escrow_account_id = auction_row.get::<u32, _>("escrow_account_id");
    let current_price = auction_row.get::<u64, _>("current_price");

    ensure_player_offline(&mut tx, player_id).await?;
    ensure_player_in_escrow(&mut tx, player_id, escrow_account_id).await?;

    let response = match (
        auction_row.get::<Option<u32>, _>("current_winner_account_id"),
        auction_row.get::<Option<u64>, _>("current_winner_bid_limit"),
    ) {
        (None, None) => {
            lock_accounts_in_order(&mut tx, &[seller_account_id, escrow_account_id]).await?;
            transfer_player_to_account(&mut tx, player_id, escrow_account_id, seller_account_id)
                .await?;

            sqlx::query(
                r#"
                UPDATE char_bazaar_auctions
                SET status = 'ended',
                    active_slot = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                "#,
            )
            .bind(auction_id)
            .execute(&mut *tx)
            .await?;

            insert_audit_event(
                &mut tx,
                auction_id,
                "auction_ended_without_winner",
                request.actor_account_id,
                Some(player_id),
                Some(request.request_id),
                serde_json::json!({
                    "seller_account_id": seller_account_id
                }),
            )
            .await?;

            AuctionStateChangeResponse {
                auction_id,
                player_id,
                status: "ended".to_string(),
                current_price,
                has_winner: false,
            }
        }
        (Some(winner_account_id), Some(winner_bid_limit)) => {
            if winner_account_id == seller_account_id {
                return Err(AppError::conflict("auction winner state is inconsistent"));
            }

            if winner_bid_limit < current_price || current_price == 0 {
                return Err(AppError::conflict("auction price state is inconsistent"));
            }

            lock_accounts_in_order(
                &mut tx,
                &[escrow_account_id, seller_account_id, winner_account_id],
            )
            .await?;
            ensure_reservation_row(&mut tx, winner_account_id).await?;

            let seller_balances = load_account_coin_balances(&mut tx, seller_account_id).await?;
            let winner_balances = load_account_coin_balances(&mut tx, winner_account_id).await?;
            let reserved_coins =
                load_reserved_transferable_coins(&mut tx, winner_account_id).await?;

            if reserved_coins < winner_bid_limit {
                return Err(AppError::conflict(
                    "winner reservation state is inconsistent",
                ));
            }

            if winner_balances.coins < current_price
                || winner_balances.coins_transferable < current_price
            {
                return Err(AppError::conflict(
                    "winner no longer has sufficient balance to settle auction",
                ));
            }

            if seller_balances.coins > MAX_ACCOUNT_COINS.saturating_sub(current_price)
                || seller_balances.coins_transferable
                    > MAX_ACCOUNT_COINS.saturating_sub(current_price)
            {
                return Err(AppError::conflict(
                    "seller balance would overflow during settlement",
                ));
            }

            update_account_coin_balances(
                &mut tx,
                winner_account_id,
                winner_balances.coins - current_price,
                winner_balances.coins_transferable - current_price,
            )
            .await?;
            update_account_coin_balances(
                &mut tx,
                seller_account_id,
                seller_balances.coins + current_price,
                seller_balances.coins_transferable + current_price,
            )
            .await?;
            update_reserved_transferable_coins(
                &mut tx,
                winner_account_id,
                reserved_coins - winner_bid_limit,
            )
            .await?;
            transfer_player_to_account(&mut tx, player_id, escrow_account_id, winner_account_id)
                .await?;

            sqlx::query(
                r#"
                UPDATE char_bazaar_auctions
                SET status = 'settled',
                    active_slot = NULL,
                    settled_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                "#,
            )
            .bind(auction_id)
            .execute(&mut *tx)
            .await?;

            insert_coin_transaction(
                &mut tx,
                winner_account_id,
                COIN_TRANSACTION_TYPE_REMOVE,
                current_price,
                format!("Character Bazaar purchase #{}", auction_id),
            )
            .await?;
            insert_coin_transaction(
                &mut tx,
                seller_account_id,
                COIN_TRANSACTION_TYPE_ADD,
                current_price,
                format!("Character Bazaar sale #{}", auction_id),
            )
            .await?;

            insert_audit_event(
                &mut tx,
                auction_id,
                "auction_settled",
                request.actor_account_id,
                auction_row.get::<Option<u32>, _>("current_winner_player_id"),
                Some(request.request_id),
                serde_json::json!({
                    "seller_account_id": seller_account_id,
                    "winner_account_id": winner_account_id,
                    "sale_price": current_price,
                    "winner_bid_limit": winner_bid_limit
                }),
            )
            .await?;

            AuctionStateChangeResponse {
                auction_id,
                player_id,
                status: "settled".to_string(),
                current_price,
                has_winner: true,
            }
        }
        _ => return Err(AppError::conflict("auction winner state is inconsistent")),
    };

    tx.commit().await?;

    Ok(Json(response))
}

fn authorize_internal(headers: &HeaderMap, config: &Config) -> Result<(), AppError> {
    let token = headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .ok_or_else(|| AppError::unauthorized("missing internal bearer token"))?;

    if token != config.internal_token {
        return Err(AppError::unauthorized("invalid internal bearer token"));
    }

    Ok(())
}

fn validate_duration(hours: u16) -> Result<Duration, AppError> {
    match hours {
        24 | 72 | 168 => Ok(Duration::hours(i64::from(hours))),
        _ => Err(AppError::bad_request(
            "duration_hours must be one of: 24, 72, 168",
        )),
    }
}

async fn load_eligibility_preview(
    pool: &MySqlPool,
    min_level: u32,
    seller_account_id: u32,
    player_id: u32,
) -> Result<EligibilityPreviewResponse, AppError> {
    let row = sqlx::query(
        r#"
        SELECT
            p.id,
            p.name,
            p.account_id,
            p.level,
            p.deletion,
            p.group_id,
            EXISTS(SELECT 1 FROM players_online po WHERE po.player_id = p.id) AS is_online,
            EXISTS(
                SELECT 1
                FROM char_bazaar_auctions cba
                WHERE cba.player_id = p.id AND cba.active_slot = 1
            ) AS has_active_auction
        FROM players p
        WHERE p.id = ?
        "#,
    )
    .bind(player_id)
    .fetch_optional(pool)
    .await?;

    let Some(row) = row else {
        return Ok(EligibilityPreviewResponse {
            eligible: false,
            reasons: vec!["character not found".to_string()],
            character_name: None,
            level: None,
        });
    };

    let mut reasons = Vec::new();
    if row.get::<u32, _>("account_id") != seller_account_id {
        reasons.push("character does not belong to the seller account".to_string());
    }
    if row.get::<u32, _>("level") < min_level {
        reasons.push(format!("character level must be at least {min_level}"));
    }
    if row.get::<u64, _>("deletion") != 0 {
        reasons.push("character is scheduled for deletion".to_string());
    }
    if row.get::<u32, _>("group_id") != 1 {
        reasons.push("staff characters cannot be listed".to_string());
    }
    if row.get::<i8, _>("is_online") != 0 {
        reasons.push("character must be offline".to_string());
    }
    if row.get::<i8, _>("has_active_auction") != 0 {
        reasons.push("character already has an active auction".to_string());
    }

    Ok(EligibilityPreviewResponse {
        eligible: reasons.is_empty(),
        reasons,
        character_name: Some(row.get::<String, _>("name")),
        level: Some(row.get::<u32, _>("level")),
    })
}

async fn ensure_player_offline(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    player_id: u32,
) -> Result<(), AppError> {
    let online = sqlx::query("SELECT player_id FROM players_online WHERE player_id = ?")
        .bind(player_id)
        .fetch_optional(&mut **tx)
        .await?;

    if online.is_some() {
        return Err(AppError::conflict("character must be offline"));
    }

    Ok(())
}

async fn ensure_no_active_auction(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    player_id: u32,
) -> Result<(), AppError> {
    let row = sqlx::query(
        "SELECT id FROM char_bazaar_auctions WHERE player_id = ? AND active_slot = 1 FOR UPDATE",
    )
    .bind(player_id)
    .fetch_optional(&mut **tx)
    .await?;

    if row.is_some() {
        return Err(AppError::conflict(
            "character already has an active auction",
        ));
    }

    Ok(())
}

async fn lock_account_exists(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
) -> Result<(), AppError> {
    let row = sqlx::query("SELECT id FROM accounts WHERE id = ? FOR UPDATE")
        .bind(account_id)
        .fetch_optional(&mut **tx)
        .await?;

    if row.is_none() {
        return Err(AppError::conflict(
            "configured escrow account does not exist",
        ));
    }

    Ok(())
}

async fn ensure_account_exists_and_lock(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
) -> Result<(), AppError> {
    let row = sqlx::query("SELECT id FROM accounts WHERE id = ? FOR UPDATE")
        .bind(account_id)
        .fetch_optional(&mut **tx)
        .await?;

    if row.is_none() {
        return Err(AppError::not_found("bidder account not found"));
    }

    Ok(())
}

async fn ensure_player_belongs_to_account(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    player_id: u32,
    account_id: u32,
) -> Result<(), AppError> {
    let row = sqlx::query("SELECT id FROM players WHERE id = ? AND account_id = ?")
        .bind(player_id)
        .bind(account_id)
        .fetch_optional(&mut **tx)
        .await?;

    if row.is_none() {
        return Err(AppError::unauthorized(
            "bidder player does not belong to the bidder account",
        ));
    }

    Ok(())
}

async fn fetch_existing_auction_by_request(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    request_id: Uuid,
) -> Result<Option<u64>, AppError> {
    let row = sqlx::query("SELECT id FROM char_bazaar_auctions WHERE create_request_id = ?")
        .bind(request_id.to_string())
        .fetch_optional(&mut **tx)
        .await?;

    Ok(row.map(|row| row.get::<u64, _>("id")))
}

async fn fetch_state_change_by_request(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    request_id: Uuid,
    expected_auction_id: u64,
) -> Result<Option<AuctionStateChangeResponse>, AppError> {
    let row = sqlx::query(
        r#"
        SELECT
            a.id,
            a.player_id,
            a.status,
            a.current_price,
            a.current_winner_account_id
        FROM char_bazaar_audit_events e
        INNER JOIN char_bazaar_auctions a ON a.id = e.auction_id
        WHERE e.request_id = ?
        ORDER BY e.id DESC
        LIMIT 1
        "#,
    )
    .bind(request_id.to_string())
    .fetch_optional(&mut **tx)
    .await?;

    let Some(row) = row else {
        return Ok(None);
    };

    if row.get::<u64, _>("id") != expected_auction_id {
        return Err(AppError::conflict(
            "request_id already belongs to a different auction action",
        ));
    }

    Ok(Some(AuctionStateChangeResponse {
        auction_id: row.get::<u64, _>("id"),
        player_id: row.get::<u32, _>("player_id"),
        status: row.get::<String, _>("status"),
        current_price: row.get::<u64, _>("current_price"),
        has_winner: row
            .get::<Option<u32>, _>("current_winner_account_id")
            .is_some(),
    }))
}

async fn fetch_bid_by_request(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    request_id: Uuid,
) -> Result<Option<PlaceBidResponse>, AppError> {
    let row = sqlx::query(
        r#"
        SELECT
            b.auction_id,
            b.price_after_bid,
            b.became_winner
        FROM char_bazaar_bids b
        WHERE b.request_id = ?
        "#,
    )
    .bind(request_id.to_string())
    .fetch_optional(&mut **tx)
    .await?;

    Ok(row.map(|row| PlaceBidResponse {
        auction_id: row.get::<u64, _>("auction_id"),
        current_price: row.get::<u64, _>("price_after_bid"),
        bidder_is_winner: row.get::<i8, _>("became_winner") != 0,
    }))
}

async fn fetch_watchlist(
    pool: &MySqlPool,
    account_id: u32,
) -> Result<Vec<WatchlistAuctionResponse>, AppError> {
    let rows = sqlx::query(
        r#"
        SELECT
            a.id,
            a.player_id,
            a.seller_name_snapshot,
            a.status,
            a.starting_bid,
            a.bid_increment,
            a.current_price,
            a.current_winner_account_id,
            a.ends_at,
            w.watched_at
        FROM char_bazaar_watchlist w
        INNER JOIN char_bazaar_auctions a ON a.id = w.auction_id
        WHERE w.account_id = ?
        ORDER BY
            CASE
                WHEN a.status = 'active' AND a.ends_at > CURRENT_TIMESTAMP THEN 0
                WHEN a.status = 'active' THEN 1
                ELSE 2
            END,
            a.ends_at ASC,
            w.watched_at DESC,
            a.id DESC
        "#,
    )
    .bind(account_id)
    .fetch_all(pool)
    .await?;

    Ok(rows
        .into_iter()
        .map(|row| WatchlistAuctionResponse {
            auction_id: row.get::<u64, _>("id"),
            player_id: row.get::<u32, _>("player_id"),
            character_name: row.get::<String, _>("seller_name_snapshot"),
            status: row.get::<String, _>("status"),
            starting_bid: row.get::<u64, _>("starting_bid"),
            bid_increment: row.get::<u64, _>("bid_increment"),
            current_price: row.get::<u64, _>("current_price"),
            has_winner: row
                .get::<Option<u32>, _>("current_winner_account_id")
                .is_some(),
            ends_at: DateTime::<Utc>::from_naive_utc_and_offset(
                row.get::<chrono::NaiveDateTime, _>("ends_at"),
                Utc,
            ),
            watched_at: DateTime::<Utc>::from_naive_utc_and_offset(
                row.get::<chrono::NaiveDateTime, _>("watched_at"),
                Utc,
            ),
        })
        .collect())
}

async fn fetch_watchlist_by_request(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    request_id: Uuid,
    expected_auction_id: u64,
) -> Result<Option<WatchlistMutationResponse>, AppError> {
    let row = sqlx::query(
        r#"
        SELECT auction_id, event_type
        FROM char_bazaar_audit_events
        WHERE request_id = ?
        ORDER BY id DESC
        LIMIT 1
        "#,
    )
    .bind(request_id.to_string())
    .fetch_optional(&mut **tx)
    .await?;

    let Some(row) = row else {
        return Ok(None);
    };

    if row.get::<u64, _>("auction_id") != expected_auction_id {
        return Err(AppError::conflict(
            "request_id already belongs to a different auction action",
        ));
    }

    let watched = match row.get::<String, _>("event_type").as_str() {
        "auction_watched" => true,
        "auction_unwatched" => false,
        _ => {
            return Err(AppError::conflict(
                "request_id already belongs to a different bazaar action",
            ));
        }
    };

    Ok(Some(WatchlistMutationResponse {
        auction_id: expected_auction_id,
        watched,
    }))
}

async fn ensure_reservation_row(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
) -> Result<(), AppError> {
    sqlx::query(
        r#"
        INSERT INTO char_bazaar_coin_reservations (account_id, reserved_transferable_coins)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE account_id = account_id
        "#,
    )
    .bind(account_id)
    .execute(&mut **tx)
    .await?;

    Ok(())
}

async fn fetch_auction_row_for_update(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    auction_id: u64,
) -> Result<MySqlRow, AppError> {
    sqlx::query(
        r#"
        SELECT
            id,
            player_id,
            seller_account_id,
            escrow_account_id,
            status,
            starting_bid,
            bid_increment,
            current_price,
            current_winner_account_id,
            current_winner_player_id,
            current_winner_bid_limit,
            ends_at
        FROM char_bazaar_auctions
        WHERE id = ?
        FOR UPDATE
        "#,
    )
    .bind(auction_id)
    .fetch_optional(&mut **tx)
    .await?
    .ok_or_else(|| AppError::not_found("auction not found"))
}

fn auction_state_change_response_from_row(row: &MySqlRow) -> AuctionStateChangeResponse {
    AuctionStateChangeResponse {
        auction_id: row.get::<u64, _>("id"),
        player_id: row.get::<u32, _>("player_id"),
        status: row.get::<String, _>("status"),
        current_price: row.get::<u64, _>("current_price"),
        has_winner: row
            .get::<Option<u32>, _>("current_winner_account_id")
            .is_some(),
    }
}

async fn load_spendable_coins(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    bidder_account_id: u32,
    current_winner_account_id: Option<u32>,
    current_winner_bid_limit: Option<u64>,
) -> Result<u64, AppError> {
    let account = sqlx::query("SELECT coins_transferable FROM accounts WHERE id = ? FOR UPDATE")
        .bind(bidder_account_id)
        .fetch_one(&mut **tx)
        .await?;
    let reservation = sqlx::query(
        "SELECT reserved_transferable_coins FROM char_bazaar_coin_reservations WHERE account_id = ? FOR UPDATE",
    )
    .bind(bidder_account_id)
    .fetch_one(&mut **tx)
    .await?;

    let coins_transferable = account.get::<u64, _>("coins_transferable");
    let reserved = reservation.get::<u64, _>("reserved_transferable_coins");
    let own_current_hold = if current_winner_account_id == Some(bidder_account_id) {
        current_winner_bid_limit.unwrap_or(0)
    } else {
        0
    };

    Ok(coins_transferable
        .saturating_sub(reserved)
        .saturating_add(own_current_hold))
}

async fn shift_winning_reservation(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    new_winner_account_id: u32,
    previous_winner_account_id: Option<u32>,
    previous_winner_bid_limit: Option<u64>,
    new_winner_bid_limit: u64,
) -> Result<(), AppError> {
    if let Some(previous_winner) = previous_winner_account_id {
        ensure_reservation_row(tx, previous_winner).await?;
    }

    let previous_limit = previous_winner_bid_limit.unwrap_or(0);

    if previous_winner_account_id == Some(new_winner_account_id) {
        let row = sqlx::query(
            "SELECT reserved_transferable_coins FROM char_bazaar_coin_reservations WHERE account_id = ? FOR UPDATE",
        )
        .bind(new_winner_account_id)
        .fetch_one(&mut **tx)
        .await?;
        let reserved = row.get::<u64, _>("reserved_transferable_coins");
        let next_reserved = reserved
            .saturating_sub(previous_limit)
            .saturating_add(new_winner_bid_limit);

        sqlx::query(
            "UPDATE char_bazaar_coin_reservations SET reserved_transferable_coins = ? WHERE account_id = ?",
        )
        .bind(next_reserved)
        .bind(new_winner_account_id)
        .execute(&mut **tx)
        .await?;
        return Ok(());
    }

    if let Some(previous_winner) = previous_winner_account_id {
        let previous_row = sqlx::query(
            "SELECT reserved_transferable_coins FROM char_bazaar_coin_reservations WHERE account_id = ? FOR UPDATE",
        )
        .bind(previous_winner)
        .fetch_one(&mut **tx)
        .await?;
        let reserved = previous_row.get::<u64, _>("reserved_transferable_coins");
        let next_reserved = reserved.saturating_sub(previous_limit);

        sqlx::query(
            "UPDATE char_bazaar_coin_reservations SET reserved_transferable_coins = ? WHERE account_id = ?",
        )
        .bind(next_reserved)
        .bind(previous_winner)
        .execute(&mut **tx)
        .await?;
    }

    ensure_reservation_row(tx, new_winner_account_id).await?;
    let new_row = sqlx::query(
        "SELECT reserved_transferable_coins FROM char_bazaar_coin_reservations WHERE account_id = ? FOR UPDATE",
    )
    .bind(new_winner_account_id)
    .fetch_one(&mut **tx)
    .await?;
    let reserved = new_row.get::<u64, _>("reserved_transferable_coins");

    sqlx::query(
        "UPDATE char_bazaar_coin_reservations SET reserved_transferable_coins = ? WHERE account_id = ?",
    )
    .bind(reserved.saturating_add(new_winner_bid_limit))
    .bind(new_winner_account_id)
    .execute(&mut **tx)
    .await?;

    Ok(())
}

async fn ensure_player_in_escrow(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    player_id: u32,
    escrow_account_id: u32,
) -> Result<(), AppError> {
    let row = sqlx::query("SELECT account_id FROM players WHERE id = ? FOR UPDATE")
        .bind(player_id)
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| AppError::not_found("character not found"))?;

    if row.get::<u32, _>("account_id") != escrow_account_id {
        return Err(AppError::conflict(
            "character is not currently held in escrow",
        ));
    }

    Ok(())
}

async fn transfer_player_to_account(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    player_id: u32,
    from_account_id: u32,
    to_account_id: u32,
) -> Result<(), AppError> {
    let result = sqlx::query(
        r#"
        UPDATE players
        SET account_id = ?
        WHERE id = ? AND account_id = ?
        "#,
    )
    .bind(to_account_id)
    .bind(player_id)
    .bind(from_account_id)
    .execute(&mut **tx)
    .await?;

    if result.rows_affected() != 1 {
        return Err(AppError::conflict(
            "character ownership changed during transfer",
        ));
    }

    Ok(())
}

async fn lock_accounts_in_order(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_ids: &[u32],
) -> Result<(), AppError> {
    let mut sorted = account_ids.to_vec();
    sorted.sort_unstable();
    sorted.dedup();

    for account_id in sorted {
        let row = sqlx::query("SELECT id FROM accounts WHERE id = ? FOR UPDATE")
            .bind(account_id)
            .fetch_optional(&mut **tx)
            .await?;

        if row.is_none() {
            return Err(AppError::conflict(
                "required account row is missing for this auction",
            ));
        }
    }

    Ok(())
}

async fn load_account_coin_balances(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
) -> Result<AccountCoinBalances, AppError> {
    let row = sqlx::query("SELECT coins, coins_transferable FROM accounts WHERE id = ? FOR UPDATE")
        .bind(account_id)
        .fetch_optional(&mut **tx)
        .await?
        .ok_or_else(|| AppError::conflict("required account row is missing for this auction"))?;

    Ok(AccountCoinBalances {
        coins: row.get::<u64, _>("coins"),
        coins_transferable: row.get::<u64, _>("coins_transferable"),
    })
}

async fn update_account_coin_balances(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
    coins: u64,
    coins_transferable: u64,
) -> Result<(), AppError> {
    sqlx::query("UPDATE accounts SET coins = ?, coins_transferable = ? WHERE id = ?")
        .bind(coins)
        .bind(coins_transferable)
        .bind(account_id)
        .execute(&mut **tx)
        .await?;

    Ok(())
}

async fn load_reserved_transferable_coins(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
) -> Result<u64, AppError> {
    let row = sqlx::query(
        "SELECT reserved_transferable_coins FROM char_bazaar_coin_reservations WHERE account_id = ? FOR UPDATE",
    )
    .bind(account_id)
    .fetch_optional(&mut **tx)
    .await?
    .ok_or_else(|| AppError::conflict("winner reservation row is missing"))?;

    Ok(row.get::<u64, _>("reserved_transferable_coins"))
}

async fn update_reserved_transferable_coins(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
    reserved_transferable_coins: u64,
) -> Result<(), AppError> {
    sqlx::query(
        "UPDATE char_bazaar_coin_reservations SET reserved_transferable_coins = ? WHERE account_id = ?",
    )
    .bind(reserved_transferable_coins)
    .bind(account_id)
    .execute(&mut **tx)
    .await?;

    Ok(())
}

async fn insert_coin_transaction(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    account_id: u32,
    transaction_type: u8,
    amount: u64,
    description: String,
) -> Result<(), AppError> {
    sqlx::query(
        r#"
        INSERT INTO coins_transactions (account_id, type, coin_type, amount, description)
        VALUES (?, ?, ?, ?, ?)
        "#,
    )
    .bind(account_id)
    .bind(transaction_type)
    .bind(COIN_TYPE_TRANSFERABLE)
    .bind(amount)
    .bind(description)
    .execute(&mut **tx)
    .await?;

    Ok(())
}

async fn insert_audit_event(
    tx: &mut sqlx::Transaction<'_, sqlx::MySql>,
    auction_id: u64,
    event_type: &str,
    actor_account_id: Option<u32>,
    actor_player_id: Option<u32>,
    request_id: Option<Uuid>,
    payload_json: serde_json::Value,
) -> Result<(), AppError> {
    sqlx::query(
        r#"
        INSERT INTO char_bazaar_audit_events (
            auction_id,
            event_type,
            actor_account_id,
            actor_player_id,
            request_id,
            payload_json
        )
        VALUES (?, ?, ?, ?, ?, ?)
        "#,
    )
    .bind(auction_id)
    .bind(event_type)
    .bind(actor_account_id)
    .bind(actor_player_id)
    .bind(request_id.map(|value| value.to_string()))
    .bind(payload_json.to_string())
    .execute(&mut **tx)
    .await?;

    Ok(())
}

async fn fetch_auction(pool: &MySqlPool, auction_id: u64) -> Result<AuctionViewResponse, AppError> {
    let row = sqlx::query(
        r#"
        SELECT
            id,
            player_id,
            seller_name_snapshot,
            status,
            starting_bid,
            bid_increment,
            current_price,
            current_winner_account_id,
            ends_at
        FROM char_bazaar_auctions
        WHERE id = ?
        "#,
    )
    .bind(auction_id)
    .fetch_optional(pool)
    .await?
    .ok_or_else(|| AppError::not_found("auction not found"))?;

    Ok(AuctionViewResponse {
        auction_id: row.get::<u64, _>("id"),
        player_id: row.get::<u32, _>("player_id"),
        character_name: row.get::<String, _>("seller_name_snapshot"),
        status: row.get::<String, _>("status"),
        starting_bid: row.get::<u64, _>("starting_bid"),
        bid_increment: row.get::<u64, _>("bid_increment"),
        current_price: row.get::<u64, _>("current_price"),
        has_winner: row
            .get::<Option<u32>, _>("current_winner_account_id")
            .is_some(),
        ends_at: DateTime::<Utc>::from_naive_utc_and_offset(
            row.get::<chrono::NaiveDateTime, _>("ends_at"),
            Utc,
        ),
    })
}
