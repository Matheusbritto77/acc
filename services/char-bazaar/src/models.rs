use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

#[derive(Debug, Deserialize)]
pub struct EligibilityPreviewRequest {
    pub seller_account_id: u32,
    pub player_id: u32,
}

#[derive(Debug, Serialize)]
pub struct EligibilityPreviewResponse {
    pub eligible: bool,
    pub reasons: Vec<String>,
    pub character_name: Option<String>,
    pub level: Option<u32>,
}

#[derive(Debug, Deserialize)]
pub struct CreateAuctionRequest {
    pub request_id: Uuid,
    pub seller_account_id: u32,
    pub player_id: u32,
    pub starting_bid: u64,
    pub bid_increment: Option<u64>,
    pub duration_hours: u16,
}

#[derive(Debug, Serialize)]
pub struct AuctionCreatedResponse {
    pub auction_id: u64,
    pub player_id: u32,
    pub ends_at: DateTime<Utc>,
    pub status: &'static str,
}

#[derive(Debug, Deserialize)]
pub struct PlaceBidRequest {
    pub request_id: Uuid,
    pub bidder_account_id: u32,
    pub bidder_player_id: u32,
    pub bid_limit: u64,
}

#[derive(Debug, Deserialize)]
pub struct CancelAuctionRequest {
    pub request_id: Uuid,
    pub seller_account_id: u32,
}

#[derive(Debug, Deserialize)]
pub struct SettleAuctionRequest {
    pub request_id: Uuid,
    pub actor_account_id: Option<u32>,
}

#[derive(Debug, Serialize)]
pub struct PlaceBidResponse {
    pub auction_id: u64,
    pub current_price: u64,
    pub bidder_is_winner: bool,
}

#[derive(Debug, Serialize)]
pub struct AuctionStateChangeResponse {
    pub auction_id: u64,
    pub player_id: u32,
    pub status: String,
    pub current_price: u64,
    pub has_winner: bool,
}

#[derive(Debug, Serialize)]
pub struct AuctionViewResponse {
    pub auction_id: u64,
    pub player_id: u32,
    pub character_name: String,
    pub status: String,
    pub starting_bid: u64,
    pub bid_increment: u64,
    pub current_price: u64,
    pub has_winner: bool,
    pub ends_at: DateTime<Utc>,
}

#[derive(Debug, Serialize)]
pub struct HealthResponse {
    pub status: &'static str,
}

#[cfg(test)]
mod tests {
    use super::{AuctionViewResponse, PlaceBidResponse};
    use chrono::Utc;

    #[test]
    fn place_bid_response_does_not_serialize_internal_winner_fields() {
        let response = PlaceBidResponse {
            auction_id: 7,
            current_price: 900,
            bidder_is_winner: true,
        };

        let json = serde_json::to_value(response).expect("response should serialize");
        assert!(json.get("current_winner_account_id").is_none());
        assert!(json.get("current_winner_bid_limit").is_none());
    }

    #[test]
    fn auction_view_response_does_not_serialize_hidden_bid_limit() {
        let response = AuctionViewResponse {
            auction_id: 9,
            player_id: 42,
            character_name: "Knight Sample".to_string(),
            status: "active".to_string(),
            starting_bid: 500,
            bid_increment: 50,
            current_price: 750,
            has_winner: true,
            ends_at: Utc::now(),
        };

        let json = serde_json::to_value(response).expect("response should serialize");
        assert!(json.get("current_winner_account_id").is_none());
        assert!(json.get("current_winner_bid_limit").is_none());
        assert!(json.get("seller_account_id").is_none());
    }
}
