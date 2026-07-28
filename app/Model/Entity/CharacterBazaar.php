<?php

namespace App\Model\Entity;

use App\DatabaseManager\Database;

class CharacterBazaar
{
    private static bool $schemaBootstrapped = false;

    public static function getPublicAuctions(?string $status = 'active', int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $database = new Database();
        $params = [];
        $where = [];

        if (is_string($status) && $status !== '') {
            $params[] = $status;
            $where[] = 'a.status = ?';
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(a.seller_name_snapshot) LIKE ? OR LOWER(p.name) LIKE ?)';
            $like = '%' . mb_strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $statement = self::query(
            $database,
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
            {$whereSql}
            ORDER BY
                CASE WHEN a.status = 'active' THEN 0 ELSE 1 END,
                a.ends_at ASC,
                a.id DESC
            LIMIT {$limit} OFFSET {$offset}
            ",
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getAuctionHistory(int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $database = new Database();
        $params = [];
        $where = ['a.status <> \'active\''];

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(a.seller_name_snapshot) LIKE ? OR LOWER(p.name) LIKE ?)';
            $like = '%' . mb_strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $statement = self::query(
            $database,
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
                a.settled_at,
                a.cancelled_at,
                a.level_snapshot,
                a.vocation_snapshot,
                a.looktype_snapshot,
                p.world
            FROM char_bazaar_auctions a
            INNER JOIN players p ON p.id = a.player_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                COALESCE(a.settled_at, a.cancelled_at, a.ends_at) DESC,
                a.id DESC
            LIMIT {$limit} OFFSET {$offset}
            "
            ,
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getPublicAuctionById(int $auctionId): ?array
    {
        $database = new Database();
        $statement = self::query(
            $database,
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

    public static function getMyListings(int $accountId, int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $database = new Database();
        $params = [$accountId];
        $where = ['a.seller_account_id = ?'];

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(a.seller_name_snapshot) LIKE ? OR LOWER(p.name) LIKE ?)';
            $like = '%' . mb_strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $statement = self::query(
            $database,
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
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT {$limit} OFFSET {$offset}
            ",
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getMyBids(int $accountId, int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $database = new Database();
        $params = [$accountId];
        $where = ['b.bidder_account_id = ?'];

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(a.seller_name_snapshot) LIKE ? OR LOWER(p.name) LIKE ?)';
            $like = '%' . mb_strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $statement = self::query(
            $database,
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
                a.ends_at,
                p.world
            FROM char_bazaar_bids b
            INNER JOIN char_bazaar_auctions a ON a.id = b.auction_id
            INNER JOIN players p ON p.id = a.player_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY b.created_at DESC, b.id DESC
            LIMIT {$limit} OFFSET {$offset}
            ",
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function getWatchedAuctions(int $accountId, int $limit = 50, int $offset = 0, ?string $search = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $database = new Database();
        $params = [$accountId];
        $where = ['w.account_id = ?'];

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(LOWER(a.seller_name_snapshot) LIKE ? OR LOWER(p.name) LIKE ?)';
            $like = '%' . mb_strtolower($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $statement = self::query(
            $database,
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
                w.watched_at,
                p.world
            FROM char_bazaar_watchlist w
            INNER JOIN char_bazaar_auctions a ON a.id = w.auction_id
            INNER JOIN players p ON p.id = a.player_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE
                    WHEN a.status = 'active' AND a.ends_at > CURRENT_TIMESTAMP THEN 0
                    WHEN a.status = 'active' THEN 1
                    ELSE 2
                END,
                a.ends_at ASC,
                w.watched_at DESC,
                a.id DESC
            LIMIT {$limit} OFFSET {$offset}
            ",
            $params
        );

        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public static function isAuctionWatched(int $accountId, int $auctionId): bool
    {
        $database = new Database();
        $statement = self::query(
            $database,
            "
            SELECT 1
            FROM char_bazaar_watchlist
            WHERE account_id = ? AND auction_id = ?
            LIMIT 1
            ",
            [$accountId, $auctionId]
        );

        return (bool) $statement->fetchColumn();
    }

    public static function getEligibleCharacters(int $accountId): array
    {
        $database = new Database();
        $statement = self::query(
            $database,
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

    private static function query(Database $database, string $query, array $params = []): \PDOStatement
    {
        try {
            return $database->executeOrFail($query, $params);
        } catch (\PDOException $exception) {
            if (!self::isMissingBazaarSchemaError($exception)) {
                throw $exception;
            }

            self::bootstrapSchema();

            return $database->executeOrFail($query, $params);
        }
    }

    private static function isMissingBazaarSchemaError(\PDOException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();

        return $code === '42S02' || $code === '1146' || strpos($message, 'char_bazaar_') !== false;
    }

    private static function bootstrapSchema(): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }

        self::$schemaBootstrapped = true;

        $schemaFile = dirname(__DIR__, 3) . '/services/char-bazaar/migrations/0001_char_bazaar.sql';
        if (!is_file($schemaFile)) {
            return;
        }

        $sql = trim((string) file_get_contents($schemaFile));
        if ($sql === '') {
            return;
        }

        $database = new Database();
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $database->executeOrFail($statement);
        }
    }
}
