CREATE TABLE IF NOT EXISTS `char_bazaar_auctions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_id` INT(11) NOT NULL,
  `seller_account_id` INT(11) UNSIGNED NOT NULL,
  `seller_name_snapshot` VARCHAR(255) NOT NULL,
  `level_snapshot` INT(11) NOT NULL,
  `vocation_snapshot` INT(11) NOT NULL,
  `looktype_snapshot` INT(11) NOT NULL,
  `escrow_account_id` INT(11) UNSIGNED NOT NULL,
  `create_request_id` CHAR(36) NOT NULL,
  `status` ENUM('active', 'ended', 'settled', 'cancelled') NOT NULL DEFAULT 'active',
  `active_slot` TINYINT UNSIGNED DEFAULT 1,
  `starting_bid` BIGINT UNSIGNED NOT NULL,
  `bid_increment` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `current_price` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `current_winner_account_id` INT(11) UNSIGNED DEFAULT NULL,
  `current_winner_player_id` INT(11) DEFAULT NULL,
  `current_winner_bid_limit` BIGINT UNSIGNED DEFAULT NULL,
  `reserve_price` BIGINT UNSIGNED DEFAULT NULL,
  `ends_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `settled_at` DATETIME DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_char_bazaar_create_request` (`create_request_id`),
  UNIQUE KEY `uniq_char_bazaar_player_active_slot` (`player_id`, `active_slot`),
  KEY `idx_char_bazaar_status_ends_at` (`status`, `ends_at`),
  CONSTRAINT `char_bazaar_auctions_player_fk`
    FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
  CONSTRAINT `char_bazaar_auctions_seller_account_fk`
    FOREIGN KEY (`seller_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `char_bazaar_bids` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `auction_id` BIGINT UNSIGNED NOT NULL,
  `bidder_account_id` INT(11) UNSIGNED NOT NULL,
  `bidder_player_id` INT(11) NOT NULL,
  `request_id` CHAR(36) NOT NULL,
  `bid_limit` BIGINT UNSIGNED NOT NULL,
  `price_after_bid` BIGINT UNSIGNED NOT NULL,
  `became_winner` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_char_bazaar_bid_request` (`request_id`),
  KEY `idx_char_bazaar_bids_auction_created` (`auction_id`, `created_at`),
  CONSTRAINT `char_bazaar_bids_auction_fk`
    FOREIGN KEY (`auction_id`) REFERENCES `char_bazaar_auctions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `char_bazaar_bids_account_fk`
    FOREIGN KEY (`bidder_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `char_bazaar_bids_player_fk`
    FOREIGN KEY (`bidder_player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `char_bazaar_watchlist` (
  `account_id` INT(11) UNSIGNED NOT NULL,
  `auction_id` BIGINT UNSIGNED NOT NULL,
  `watched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `auction_id`),
  KEY `idx_char_bazaar_watchlist_auction` (`auction_id`),
  KEY `idx_char_bazaar_watchlist_account_watched` (`account_id`, `watched_at`),
  CONSTRAINT `char_bazaar_watchlist_account_fk`
    FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `char_bazaar_watchlist_auction_fk`
    FOREIGN KEY (`auction_id`) REFERENCES `char_bazaar_auctions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `char_bazaar_coin_reservations` (
  `account_id` INT(11) UNSIGNED NOT NULL,
  `reserved_transferable_coins` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  CONSTRAINT `char_bazaar_coin_reservations_account_fk`
    FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `char_bazaar_audit_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `auction_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `actor_account_id` INT(11) UNSIGNED DEFAULT NULL,
  `actor_player_id` INT(11) DEFAULT NULL,
  `request_id` CHAR(36) DEFAULT NULL,
  `payload_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_char_bazaar_audit_auction_created` (`auction_id`, `created_at`),
  CONSTRAINT `char_bazaar_audit_auction_fk`
    FOREIGN KEY (`auction_id`) REFERENCES `char_bazaar_auctions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
