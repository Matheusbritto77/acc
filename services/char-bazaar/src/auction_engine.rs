use crate::error::AppError;

#[derive(Debug, Clone, Copy)]
pub struct AuctionBidState {
    pub starting_bid: u64,
    pub bid_increment: u64,
    pub current_price: u64,
    pub current_winner_account_id: Option<u32>,
    pub current_winner_bid_limit: Option<u64>,
}

#[derive(Debug, Clone, Copy)]
pub struct BidDecision {
    pub next_price: u64,
    pub next_winner_account_id: u32,
    pub next_winner_bid_limit: u64,
    pub previous_winner_account_id: Option<u32>,
    pub bidder_is_winner: bool,
}

pub fn apply_bid(
    state: AuctionBidState,
    bidder_account_id: u32,
    bid_limit: u64,
) -> Result<BidDecision, AppError> {
    let increment = state.bid_increment.max(1);
    if bid_limit < state.starting_bid {
        return Err(AppError::bad_request("bid limit is below the starting bid"));
    }

    match (
        state.current_winner_account_id,
        state.current_winner_bid_limit,
    ) {
        (None, None) => Ok(BidDecision {
            next_price: state.starting_bid,
            next_winner_account_id: bidder_account_id,
            next_winner_bid_limit: bid_limit,
            previous_winner_account_id: None,
            bidder_is_winner: true,
        }),
        (Some(current_winner), Some(current_limit)) if current_winner == bidder_account_id => {
            if bid_limit <= current_limit {
                return Err(AppError::bad_request(
                    "new bid limit must exceed the current winner limit",
                ));
            }

            Ok(BidDecision {
                next_price: state.current_price.max(state.starting_bid),
                next_winner_account_id: bidder_account_id,
                next_winner_bid_limit: bid_limit,
                previous_winner_account_id: None,
                bidder_is_winner: true,
            })
        }
        (Some(current_winner), Some(current_limit)) => {
            if bid_limit <= current_limit {
                Ok(BidDecision {
                    next_price: current_limit
                        .min(bid_limit.saturating_add(increment))
                        .max(state.current_price),
                    next_winner_account_id: current_winner,
                    next_winner_bid_limit: current_limit,
                    previous_winner_account_id: None,
                    bidder_is_winner: false,
                })
            } else {
                Ok(BidDecision {
                    next_price: bid_limit
                        .min(current_limit.saturating_add(increment))
                        .max(state.starting_bid),
                    next_winner_account_id: bidder_account_id,
                    next_winner_bid_limit: bid_limit,
                    previous_winner_account_id: Some(current_winner),
                    bidder_is_winner: true,
                })
            }
        }
        _ => Err(AppError::conflict("auction winner state is inconsistent")),
    }
}

#[cfg(test)]
mod tests {
    use super::{AuctionBidState, apply_bid};

    #[test]
    fn first_bid_sets_starting_price() {
        let decision = apply_bid(
            AuctionBidState {
                starting_bid: 500,
                bid_increment: 50,
                current_price: 0,
                current_winner_account_id: None,
                current_winner_bid_limit: None,
            },
            10,
            1200,
        )
        .expect("first bid should work");

        assert_eq!(decision.next_price, 500);
        assert_eq!(decision.next_winner_account_id, 10);
        assert_eq!(decision.next_winner_bid_limit, 1200);
        assert!(decision.bidder_is_winner);
    }

    #[test]
    fn lower_new_bid_keeps_current_winner() {
        let decision = apply_bid(
            AuctionBidState {
                starting_bid: 500,
                bid_increment: 50,
                current_price: 500,
                current_winner_account_id: Some(10),
                current_winner_bid_limit: Some(1200),
            },
            20,
            900,
        )
        .expect("lower bid should still be processed");

        assert_eq!(decision.next_price, 950);
        assert_eq!(decision.next_winner_account_id, 10);
        assert!(!decision.bidder_is_winner);
    }

    #[test]
    fn higher_new_bid_replaces_current_winner() {
        let decision = apply_bid(
            AuctionBidState {
                starting_bid: 500,
                bid_increment: 50,
                current_price: 950,
                current_winner_account_id: Some(10),
                current_winner_bid_limit: Some(1200),
            },
            20,
            1500,
        )
        .expect("higher bid should replace the winner");

        assert_eq!(decision.next_price, 1250);
        assert_eq!(decision.next_winner_account_id, 20);
        assert_eq!(decision.previous_winner_account_id, Some(10));
        assert!(decision.bidder_is_winner);
    }
}
