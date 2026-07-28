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
        $auctions = array_map([self::class, 'mapPublicAuction'], EntityCharacterBazaar::getPublicAuctions('active'));
        $accountId = self::getLoggedAccountId();

        $content = View::render('pages/community/charbazaar', [
            'auctions' => $auctions,
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'is_logged_in' => $accountId > 0,
            'logged_account_id' => $accountId,
            'step_up_fresh' => $accountId > 0 ? StepUpAuthentication::isFresh($accountId) : false,
        ]);

        return parent::getBase('Character Bazaar', $content, 'charbazaar');
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
            'return_to' => '/community/char-bazaar/' . (int) $auctionId,
            'bidder_characters' => $accountId > 0 ? self::getBidderCharacters($accountId) : [],
        ]);

        return parent::getBase('Character Bazaar', $content, 'charbazaar');
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
            '/community/char-bazaar/' . (int) $auctionId . '?' . http_build_query([
                'status_type' => 'success',
                'status_message' => $message,
            ])
        );
    }

    public static function viewMyListings($request)
    {
        return self::renderMyListingsPage($request);
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
            '/account/char-bazaar/my-listings?' . http_build_query([
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

        $request->getRouter()->redirect(
            '/account/char-bazaar/my-listings?' . http_build_query([
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

        $request->getRouter()->redirect(
            '/account/char-bazaar/my-listings?' . http_build_query([
                'status_type' => 'success',
                'status_message' => 'Listing settled successfully.',
            ])
        );
    }

    public static function viewMyBids($request)
    {
        $accountId = self::getLoggedAccountId();
        $status = self::getStatusFromRequest($request);
        $bids = array_map([self::class, 'mapBidRow'], EntityCharacterBazaar::getMyBids($accountId));

        $content = View::render('pages/account/charbazaar_bids', [
            'bids' => $bids,
            'status_type' => $status['type'],
            'status_message' => $status['message'],
            'step_up_fresh' => StepUpAuthentication::isFresh($accountId),
            'step_up_remaining' => StepUpAuthentication::getRemainingSeconds($accountId),
            'return_to' => '/account/char-bazaar/my-bids',
        ]);

        return parent::getBase('Account Management', $content, 'account');
    }

    public static function confirmStepUp($request)
    {
        $accountId = self::getLoggedAccountId();
        $postVars = $request->getPostVars();
        $returnTo = self::normalizeReturnTo(
            (string) ($postVars['return_to'] ?? ''),
            '/account/char-bazaar/my-listings'
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
        $characters = array_map([self::class, 'mapCharacterRow'], EntityCharacterBazaar::getEligibleCharacters($accountId));
        $listings = array_map([self::class, 'mapPrivateAuction'], EntityCharacterBazaar::getMyListings($accountId));

        $content = View::render('pages/account/charbazaar_listings', [
            'characters' => $characters,
            'listings' => $listings,
            'preview' => $overrides['preview'] ?? null,
            'selected_player_id' => (int) ($overrides['selected_player_id'] ?? 0),
            'status_type' => $overrides['status_type'] ?? $status['type'],
            'status_message' => $overrides['status_message'] ?? $status['message'],
            'step_up_fresh' => StepUpAuthentication::isFresh($accountId),
            'step_up_remaining' => StepUpAuthentication::getRemainingSeconds($accountId),
            'return_to' => '/account/char-bazaar/my-listings',
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
            'return_to' => '/community/char-bazaar/' . (int) $auction['id'],
            'bidder_characters' => $accountId > 0 ? self::getBidderCharacters($accountId) : [],
        ]);

        return parent::getBase('Character Bazaar', $content, 'charbazaar');
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
