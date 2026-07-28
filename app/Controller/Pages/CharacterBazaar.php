<?php

namespace App\Controller\Pages;

use App\Model\Entity\CharacterBazaar as EntityCharacterBazaar;
use App\Model\Entity\Player as EntityPlayer;
use App\Security\StepUpAuthentication;
use App\Service\CharacterBazaarClient;
use App\Session\Admin\Login as SessionAdminLogin;
use App\Utils\View;
use App\Model\Functions\Player as PlayerFunctions;

class CharacterBazaar extends Base
{
    public static function viewMarketplace($request)
    {
        $status = self::getStatusFromRequest($request);
        $filters = self::getBazaarFilters($request, 25);
        $rawAuctions = EntityCharacterBazaar::getPublicAuctions('active', $filters['per_page'] + 1, $filters['offset'], $filters['search']);
        [$auctions, $pagination] = self::paginateRows(array_map([self::class, 'mapPublicAuction'], $rawAuctions), $filters, '/charactertrade');
        $accountId = self::getLoggedAccountId();

        $content = View::render('pages/community/charbazaar', [
            'auctions' => $auctions,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'per_page' => $filters['per_page'],
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
        ]);

        return parent::getBase('Character Bazaar', $content, 'charactertrade');
    }

    public static function viewAuctionHistory($request)
    {
        $status = self::getStatusFromRequest($request);
        $filters = self::getBazaarFilters($request, 25);
        $rawAuctions = EntityCharacterBazaar::getAuctionHistory($filters['per_page'] + 1, $filters['offset'], $filters['search']);
        [$auctions, $pagination] = self::paginateRows(array_map([self::class, 'mapHistoryAuction'], $rawAuctions), $filters, '/charactertrade/history');
        $accountId = self::getLoggedAccountId();

        $content = View::render('pages/community/charbazaar_history', [
            'auctions' => $auctions,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'per_page' => $filters['per_page'],
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
        ]);

        return parent::getBase('Character Bazaar', $content, 'charactertrade');
    }

    public static function viewAuction($request, $auctionId)
    {
        $status = self::getStatusFromRequest($request);
        $auction = EntityCharacterBazaar::getPublicAuctionById((int) $auctionId);
        $accountId = self::getLoggedAccountId();

        $content = View::render('pages/community/charbazaar_view', [
            'auction' => is_array($auction) ? self::mapPublicAuction($auction) : null,
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
            'step_up_remaining' => $accountId > 0 ? StepUpAuthentication::getRemainingSeconds($accountId) : 0,
            'return_to' => '/charactertrade/' . (int) $auctionId,
            'bidder_characters' => $accountId > 0 ? self::getBidderCharacters($accountId) : [],
            'is_watching' => $accountId > 0 && is_array($auction)
                ? EntityCharacterBazaar::isAuctionWatched($accountId, (int) $auctionId)
                : false,
        ]);

        return parent::getBase('Character Bazaar', $content, 'charactertrade');
    }

    public static function placeBid($request, $auctionId)
    {
        $accountId = self::getLoggedAccountId();
        $auction = EntityCharacterBazaar::getPublicAuctionById((int) $auctionId);
        if (!is_array($auction)) {
            return self::viewAuction($request, $auctionId);
        }

        if (!StepUpAuthentication::isFresh($accountId)) {
            return self::renderAuctionPage(
                $request,
                $auction,
                'error',
                'Confirm your password and authenticator token before placing a bid.'
            );
        }

        $postVars = $request->getPostVars();
        $bidderPlayerId = (int) ($postVars['bidder_player_id'] ?? 0);
        $bidLimit = (int) ($postVars['bid_limit'] ?? 0);
        if ($bidderPlayerId <= 0 || $bidLimit <= 0) {
            return self::renderAuctionPage(
                $request,
                $auction,
                'error',
                'Choose one of your characters and enter a valid bid limit.'
            );
        }

        $response = CharacterBazaarClient::post('/v1/auctions/' . (int) $auctionId . '/bids', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'bidder_account_id' => $accountId,
            'bidder_player_id' => $bidderPlayerId,
            'bid_limit' => $bidLimit,
        ]);

        if (!$response['ok']) {
            return self::renderAuctionPage($request, $auction, 'error', (string) $response['error']);
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $message = !empty($data['bidder_is_winner'])
            ? 'Your bid was accepted and you are currently the highest bidder.'
            : 'Your bid was recorded. Another bidder still leads this auction.';

        $request->getRouter()->redirect(
            '/charactertrade/' . (int) $auctionId . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => $message,
            ])
        );
    }

    public static function watchAuction($request, $auctionId)
    {
        $accountId = self::getLoggedAccountId();
        $auction = EntityCharacterBazaar::getPublicAuctionById((int) $auctionId);
        if (!is_array($auction)) {
            return self::viewAuction($request, $auctionId);
        }

        if ((int) ($auction['seller_account_id'] ?? 0) === $accountId) {
            return self::renderAuctionPage($request, $auction, 'error', 'You cannot watch your own auction.');
        }

        $postVars = $request->getPostVars();
        $response = CharacterBazaarClient::post('/v1/auctions/' . (int) $auctionId . '/watch', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'account_id' => $accountId,
        ]);

        if (!$response['ok']) {
            return self::renderAuctionPage($request, $auction, 'error', (string) $response['error']);
        }

        $returnTo = self::normalizeReturnTo(
            (string) ($postVars['return_to'] ?? ''),
            '/charactertrade/' . (int) $auctionId
        );

        $request->getRouter()->redirect(
            $returnTo . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Auction added to your watched auctions.',
            ])
        );
    }

    public static function unwatchAuction($request, $auctionId)
    {
        $accountId = self::getLoggedAccountId();
        $auction = EntityCharacterBazaar::getPublicAuctionById((int) $auctionId);
        if (!is_array($auction)) {
            return self::viewAuction($request, $auctionId);
        }

        $postVars = $request->getPostVars();
        $response = CharacterBazaarClient::post('/v1/auctions/' . (int) $auctionId . '/unwatch', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'account_id' => $accountId,
        ]);

        if (!$response['ok']) {
            return self::renderAuctionPage($request, $auction, 'error', (string) $response['error']);
        }

        $returnTo = self::normalizeReturnTo(
            (string) ($postVars['return_to'] ?? ''),
            '/charactertrade/' . (int) $auctionId
        );

        $request->getRouter()->redirect(
            $returnTo . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Auction removed from your watched auctions.',
            ])
        );
    }

    public static function viewMyListings($request)
    {
        return self::renderMyListingsPage($request);
    }

    public static function viewWatchedAuctions($request)
    {
        $status = self::getStatusFromRequest($request);
        $accountId = self::getLoggedAccountId();
        $filters = self::getBazaarFilters($request, 25);
        $rawAuctions = EntityCharacterBazaar::getWatchedAuctions($accountId, $filters['per_page'] + 1, $filters['offset'], $filters['search']);
        [$watchedAuctions, $pagination] = self::paginateRows(array_map([self::class, 'mapWatchedAuction'], $rawAuctions), $filters, '/charactertrade/watched');
        [$activeWatched, $endedWatched] = self::splitWatchedAuctions($watchedAuctions);

        $content = View::render('pages/community/charbazaar_watched', [
            'active_watched' => $activeWatched,
            'ended_watched' => $endedWatched,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'per_page' => $filters['per_page'],
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
            'return_to' => '/charactertrade/watched',
        ]);

        return parent::getBase('Character Bazaar', $content, 'charactertrade');
    }

    public static function previewListing($request)
    {
        $accountId = self::getLoggedAccountId();
        $postVars = $request->getPostVars();
        $playerId = (int) ($postVars['player_id'] ?? 0);

        if ($playerId <= 0) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => 'Choose a character to preview before creating a listing.',
            ]);
        }

        $response = CharacterBazaarClient::post('/v1/auctions/preview', [
            'seller_account_id' => $accountId,
            'player_id' => $playerId,
        ]);

        if (!$response['ok']) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => (string) $response['error'],
            ]);
        }

        return self::renderMyListingsPage($request, [
            'preview' => $response['data'] ?? null,
            'selected_player_id' => $playerId,
            'status_type' => 'info',
            'status_message' => 'Eligibility preview refreshed.',
        ]);
    }

    public static function createListing($request)
    {
        $accountId = self::getLoggedAccountId();
        if (!StepUpAuthentication::isFresh($accountId)) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => 'Confirm your password and authenticator token before creating a listing.',
            ]);
        }

        $postVars = $request->getPostVars();
        $playerId = (int) ($postVars['player_id'] ?? 0);
        $startingBid = (int) ($postVars['starting_bid'] ?? 0);
        $bidIncrement = (int) ($postVars['bid_increment'] ?? 0);
        $durationHours = (int) ($postVars['duration_hours'] ?? 0);

        if ($playerId <= 0 || $startingBid <= 0 || !in_array($durationHours, [24, 72, 168], true)) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => 'Fill in character, starting bid and one valid duration before listing.',
            ]);
        }

        $response = CharacterBazaarClient::post('/v1/auctions', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'seller_account_id' => $accountId,
            'player_id' => $playerId,
            'starting_bid' => $startingBid,
            'bid_increment' => max(1, $bidIncrement),
            'duration_hours' => $durationHours,
        ]);

        if (!$response['ok']) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => (string) $response['error'],
            ]);
        }

        $request->getRouter()->redirect(
            '/account/char-bazaar/my-auctions?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Character listing created successfully.',
            ])
        );
    }

    public static function cancelListing($request, $auctionId)
    {
        $accountId = self::getLoggedAccountId();
        if (!StepUpAuthentication::isFresh($accountId)) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => 'Confirm your password and authenticator token before cancelling a listing.',
            ]);
        }

        $response = CharacterBazaarClient::post('/v1/auctions/' . (int) $auctionId . '/cancel', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'seller_account_id' => $accountId,
        ]);

        if (!$response['ok']) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => (string) $response['error'],
            ]);
        }

        $postVars = $request->getPostVars();
        $returnTo = self::normalizeReturnTo((string) ($postVars['return_to'] ?? ''), '/account/char-bazaar/my-auctions');

        $request->getRouter()->redirect(
            $returnTo . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Listing cancelled and character returned from escrow.',
            ])
        );
    }

    public static function settleListing($request, $auctionId)
    {
        $accountId = self::getLoggedAccountId();
        if (!StepUpAuthentication::isFresh($accountId)) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => 'Confirm your password and authenticator token before settling a listing.',
            ]);
        }

        $response = CharacterBazaarClient::post('/v1/auctions/' . (int) $auctionId . '/settle', [
            'request_id' => CharacterBazaarClient::createRequestId(),
            'actor_account_id' => $accountId,
        ]);

        if (!$response['ok']) {
            return self::renderMyListingsPage($request, [
                'status_type' => 'error',
                'status_message' => (string) $response['error'],
            ]);
        }

        $postVars = $request->getPostVars();
        $returnTo = self::normalizeReturnTo((string) ($postVars['return_to'] ?? ''), '/account/char-bazaar/my-auctions');

        $request->getRouter()->redirect(
            $returnTo . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Listing settled successfully.',
            ])
        );
    }

    public static function viewMyBids($request)
    {
        $accountId = self::getLoggedAccountId();
        $status = self::getStatusFromRequest($request);
        $filters = self::getBazaarFilters($request, 25);
        $rawBids = EntityCharacterBazaar::getMyBids($accountId, $filters['per_page'] + 1, $filters['offset'], $filters['search']);
        [$bids, $pagination] = self::paginateRows(array_map([self::class, 'mapBidRow'], $rawBids), $filters, '/account/char-bazaar/my-bids');

        $content = View::render('pages/account/charbazaar_bids', [
            'bids' => $bids,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'per_page' => $filters['per_page'],
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'step_up_fresh' => StepUpAuthentication::isFresh($accountId),
            'step_up_remaining' => StepUpAuthentication::getRemainingSeconds($accountId),
            'return_to' => '/account/char-bazaar/my-bids',
        ]);

        return parent::getBase('Account Management', $content, 'account');
    }

    public static function viewMyWatchedAuctions($request)
    {
        $request->getRouter()->redirect('/charactertrade/watched');
    }

    public static function confirmStepUp($request)
    {
        $accountId = self::getLoggedAccountId();
        $postVars = $request->getPostVars();
        $returnTo = self::normalizeReturnTo(
            (string) ($postVars['return_to'] ?? ''),
            '/account/char-bazaar/my-auctions'
        );

        $error = StepUpAuthentication::verifyForAccount(
            $accountId,
            (string) ($postVars['step_up_password'] ?? ''),
            (string) ($postVars['step_up_token'] ?? '')
        );

        $request->getRouter()->redirect(
            $returnTo . '?' . http_build_query([
                'status_type' => $error === null ? 'success' : 'error',
                'status_message' => $error === null
                    ? 'Sensitive bazaar actions are unlocked for a short time.'
                    : $error,
            ])
        );
    }

    private static function renderMyListingsPage($request, array $overrides = [])
    {
        $accountId = self::getLoggedAccountId();
        $status = self::getStatusFromRequest($request);
        $filters = self::getBazaarFilters($request, 25);
        $characters = array_map([self::class, 'mapCharacterRow'], EntityCharacterBazaar::getEligibleCharacters($accountId));
        $rawListings = EntityCharacterBazaar::getMyListings($accountId, $filters['per_page'] + 1, $filters['offset'], $filters['search']);
        [$listings, $pagination] = self::paginateRows(array_map([self::class, 'mapPrivateAuction'], $rawListings), $filters, '/account/char-bazaar/my-auctions');
        $returnTo = self::buildBazaarPageUrl('/account/char-bazaar/my-auctions', [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'search' => $filters['search'],
        ]);

        $content = View::render('pages/account/charbazaar_listings', [
            'characters' => $characters,
            'listings' => $listings,
            'pagination' => $pagination,
            'search' => $filters['search'],
            'per_page' => $filters['per_page'],
            'return_to' => $returnTo,
            'preview' => $overrides['preview'] ?? null,
            'selected_player_id' => (int) ($overrides['selected_player_id'] ?? 0),
            'status_type' => $overrides['status_type'] ?? $status['type'],
            'status_message' => $overrides['status_message'] ?? $status['message'],
            'step_up_fresh' => StepUpAuthentication::isFresh($accountId),
            'step_up_remaining' => StepUpAuthentication::getRemainingSeconds($accountId),
            'return_to' => '/account/char-bazaar/my-auctions',
        ]);

        return parent::getBase('Account Management', $content, 'account');
    }

    private static function renderAuctionPage($request, array $auction, string $statusType, string $statusMessage)
    {
        $accountId = self::getLoggedAccountId();
        $content = View::render('pages/community/charbazaar_view', [
            'auction' => self::mapPublicAuction($auction),
            'status_type' => $statusType,
            'status_message' => $statusMessage,
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
            'step_up_remaining' => $accountId > 0 ? StepUpAuthentication::getRemainingSeconds($accountId) : 0,
            'return_to' => '/charactertrade/' . (int) $auction['id'],
            'bidder_characters' => $accountId > 0 ? self::getBidderCharacters($accountId) : [],
            'is_watching' => $accountId > 0 ? EntityCharacterBazaar::isAuctionWatched($accountId, (int) $auction['id']) : false,
        ]);

        return parent::getBase('Character Bazaar', $content, 'charactertrade');
    }

    private static function getLoggedAccountId(): int
    {
        return (int) (SessionAdminLogin::idLogged() ?? 0);
    }

    private static function getStatusFromRequest($request): array
    {
        $queryParams = $request->getQueryParams();
        $type = (string) ($queryParams['status_type'] ?? '');
        $message = trim((string) ($queryParams['status_message'] ?? ''));

        if (!in_array($type, ['success', 'error', 'info'], true) || $message === '') {
            return ['type' => '', 'message' => ''];
        }

        return ['type' => $type, 'message' => $message];
    }

    private static function getBazaarFilters($request, int $defaultPerPage): array
    {
        $queryParams = $request->getQueryParams();
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = (int) ($queryParams['per_page'] ?? $defaultPerPage);
        $perPage = max(5, min($perPage, 50));
        $search = trim((string) ($queryParams['search'] ?? ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'search' => $search,
        ];
    }

    private static function paginateRows(array $rows, array $filters, string $basePath): array
    {
        $perPage = (int) $filters['per_page'];
        $page = (int) $filters['page'];
        $hasNext = count($rows) > $perPage;
        $items = array_slice($rows, 0, $perPage);

        return [
            $items,
            [
                'page' => $page,
                'per_page' => $perPage,
                'search' => (string) ($filters['search'] ?? ''),
                'has_prev' => $page > 1,
                'has_next' => $hasNext,
                'prev_url' => self::buildBazaarPageUrl($basePath, [
                    'page' => max(1, $page - 1),
                    'per_page' => $perPage,
                    'search' => (string) ($filters['search'] ?? ''),
                ]),
                'next_url' => self::buildBazaarPageUrl($basePath, [
                    'page' => $page + 1,
                    'per_page' => $perPage,
                    'search' => (string) ($filters['search'] ?? ''),
                ]),
            ],
        ];
    }

    private static function buildBazaarPageUrl(string $path, array $params): string
    {
        $query = array_filter($params, static fn($value) => $value !== null && $value !== '');
        return $path . (empty($query) ? '' : '?' . http_build_query($query));
    }

    private static function normalizeReturnTo(string $returnTo, string $fallback): string
    {
        if ($returnTo === '' || $returnTo[0] !== '/') {
            return $fallback;
        }

        if (strpos($returnTo, '://') !== false || strpos($returnTo, "\n") !== false || strpos($returnTo, "\r") !== false) {
            return $fallback;
        }

        return $returnTo;
    }

    private static function splitWatchedAuctions(array $auctions): array
    {
        $active = [];
        $ended = [];

        foreach ($auctions as $auction) {
            if (!empty($auction['is_ended'])) {
                $ended[] = $auction;
                continue;
            }

            $active[] = $auction;
        }

        return [$active, $ended];
    }

    private static function getBidderCharacters(int $accountId): array
    {
        $players = EntityPlayer::getPlayer(['account_id' => $accountId], 'level DESC, name ASC');
        $characters = [];

        while ($player = $players->fetchObject()) {
            if ((int) ($player->deletion ?? 0) !== 0) {
                continue;
            }

            $characters[] = [
                'id' => (int) $player->id,
                'name' => (string) $player->name,
                'level' => (int) $player->level,
                'vocation' => PlayerFunctions::convertVocation((int) $player->vocation),
            ];
        }

        return $characters;
    }

    private static function mapPublicAuction(array $auction): array
    {
        $endsAt = (string) ($auction['ends_at'] ?? '');

        return [
            'id' => (int) $auction['id'],
            'player_id' => (int) $auction['player_id'],
            'seller_account_id' => (int) ($auction['seller_account_id'] ?? 0),
            'character_name' => (string) ($auction['seller_name_snapshot'] ?? ''),
            'status' => (string) ($auction['status'] ?? ''),
            'starting_bid' => (int) ($auction['starting_bid'] ?? 0),
            'bid_increment' => (int) ($auction['bid_increment'] ?? 0),
            'current_price' => (int) ($auction['current_price'] ?? 0),
            'has_winner' => !empty($auction['current_winner_account_id']),
            'ends_at' => $endsAt,
            'is_ended' => $endsAt !== '' ? strtotime($endsAt) <= time() : false,
            'level' => (int) ($auction['level_snapshot'] ?? 0),
            'vocation' => PlayerFunctions::convertVocation((int) ($auction['vocation_snapshot'] ?? 0)),
        ];
    }

    private static function mapHistoryAuction(array $auction): array
    {
        $mapped = self::mapPublicAuction($auction);
        $mapped['final_status'] = (string) ($auction['status'] ?? '');
        $mapped['result_label'] = match ($mapped['final_status']) {
            'settled' => 'Sold',
            'cancelled' => 'Cancelled',
            'ended' => $mapped['has_winner'] ? 'Ended with winner' : 'Ended without winner',
            default => ucfirst($mapped['final_status']),
        };
        $mapped['settled_at'] = (string) ($auction['settled_at'] ?? '');
        $mapped['cancelled_at'] = (string) ($auction['cancelled_at'] ?? '');

        return $mapped;
    }

    private static function mapWatchedAuction(array $auction): array
    {
        $mapped = self::mapPrivateAuction($auction);
        $mapped['watched_at'] = (string) ($auction['watched_at'] ?? '');

        return $mapped;
    }

    private static function mapPrivateAuction(array $auction): array
    {
        $mapped = self::mapPublicAuction($auction);
        $mapped['cancelled_at'] = (string) ($auction['cancelled_at'] ?? '');
        $mapped['settled_at'] = (string) ($auction['settled_at'] ?? '');
        $mapped['can_cancel'] = $mapped['status'] === 'active' && !$mapped['has_winner'] && !$mapped['is_ended'];
        $mapped['can_settle'] = $mapped['status'] === 'active' && $mapped['is_ended'];

        return $mapped;
    }

    private static function mapBidRow(array $bid): array
    {
        $endsAt = (string) ($bid['ends_at'] ?? '');

        return [
            'auction_id' => (int) $bid['auction_id'],
            'bidder_player_id' => (int) ($bid['bidder_player_id'] ?? 0),
            'bid_limit' => (int) ($bid['bid_limit'] ?? 0),
            'price_after_bid' => (int) ($bid['price_after_bid'] ?? 0),
            'became_winner' => !empty($bid['became_winner']),
            'created_at' => (string) ($bid['created_at'] ?? ''),
            'character_name' => (string) ($bid['seller_name_snapshot'] ?? ''),
            'status' => (string) ($bid['status'] ?? ''),
            'current_price' => (int) ($bid['current_price'] ?? 0),
            'has_winner' => !empty($bid['current_winner_account_id']),
            'ends_at' => $endsAt,
            'is_ended' => $endsAt !== '' ? strtotime($endsAt) <= time() : false,
        ];
    }

    private static function mapCharacterRow(array $character): array
    {
        return [
            'id' => (int) $character['id'],
            'name' => (string) $character['name'],
            'level' => (int) ($character['level'] ?? 0),
            'vocation' => PlayerFunctions::convertVocation((int) ($character['vocation'] ?? 0)),
            'is_online' => !empty($character['is_online']),
            'has_active_auction' => !empty($character['has_active_auction']),
            'is_staff' => (int) ($character['group_id'] ?? 1) !== 1,
            'is_deleted' => (int) ($character['deletion'] ?? 0) !== 0,
        ];
    }
}
