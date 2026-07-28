<?php

namespace App\Model\Entity;

use App\DatabaseManager\Database;

class CharacterBazaar
{
    public static function getPublicAuctions(?string $status = 'active', int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $database = new Database();
        $params = [];
        $where = '';

        if (is_string($status) && $status !== '') {
            $where = 'WHERE a.status = ?';
            $params[] = $status;
        }

        $statement = $database->execute(
            "
            SELECT
                a.id,
                a.player_id,
                a.seller_account_id,
                a.seller_name_snapshot,
                a.status,
                a.starting_bid,
                a.bid_increment,
                a.current_price,
                a.current_winner_account_id,
                a.ends_at,
                a.level_snapshot,
                a.vocation_snapshot,
                a.looktype_snapshot,
                p.world
            FROM char_bazaar_auctions a
            INNER JOIN players p ON p.id = a.player_id
            {$where}
            ORDER BY
                CASE WHEN a.status = 'active' THEN 0 ELSE 1 END,
                a.ends_at ASC,
                a.id DESC
            LIMIT {$limit}
            ",
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getPublicAuctionById(int $auctionId): ?array
    {
        $database = new Database();
        $statement = $database->execute(
            "
            SELECT
                a.id,
                a.player_id,
                a.seller_account_id,
                a.seller_name_snapshot,
                a.status,
                a.starting_bid,
                a.bid_increment,
                a.current_price,
                a.current_winner_account_id,
                a.ends_at,
                a.level_snapshot,
                a.vocation_snapshot,
                a.looktype_snapshot,
                p.world
            FROM char_bazaar_auctions a
            INNER JOIN players p ON p.id = a.player_id
            WHERE a.id = ?
            LIMIT 1
            ",
            [$auctionId]
        );

        $auction = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($auction) ? $auction : null;
    }

    public static function getMyListings(int $accountId): array
    {
        $database = new Database();
        $statement = $database->execute(
            "
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
                a.cancelled_at,
                a.settled_at,
                a.level_snapshot,
                a.vocation_snapshot,
                a.looktype_snapshot,
                p.world
            FROM char_bazaar_auctions a
            INNER JOIN players p ON p.id = a.player_id
            WHERE a.seller_account_id = ?
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 100
            ",
            [$accountId]
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getMyBids(int $accountId): array
    {
        $database = new Database();
        $statement = $database->execute(
            "
            SELECT
                b.auction_id,
                b.bidder_player_id,
                b.bid_limit,
                b.price_after_bid,
                b.became_winner,
                b.created_at,
                a.player_id,
                a.seller_name_snapshot,
                a.status,
                a.current_price,
                a.current_winner_account_id,
                a.ends_at
            FROM char_bazaar_bids b
            INNER JOIN char_bazaar_auctions a ON a.id = b.auction_id
            WHERE b.bidder_account_id = ?
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT 150
            ",
            [$accountId]
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getEligibleCharacters(int $accountId): array
    {
        $database = new Database();
        $statement = $database->execute(
            "
            SELECT
                p.id,
                p.name,
                p.level,
                p.vocation,
                p.looktype,
                p.group_id,
                p.deletion,
                p.world,
                EXISTS(SELECT 1 FROM players_online po WHERE po.player_id = p.id) AS is_online,
                EXISTS(
                    SELECT 1
                    FROM char_bazaar_auctions a
                    WHERE a.player_id = p.id AND a.active_slot = 1
                ) AS has_active_auction
            FROM players p
            WHERE p.account_id = ?
            ORDER BY p.level DESC, p.name ASC
            ",
            [$accountId]
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
