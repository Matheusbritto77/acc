<?php
/**
 * Login Class
 *
 * @package   CanaryAAC
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 CanaryAAC
 */

namespace App\Controller\Api;

use App\Model\Entity\Account as EntityAccount;
use App\Model\Entity\Bans;
use App\Controller\Admin\Compendium as EntityCompendium;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Entity\ServerConfig;
use App\Model\Functions\EventSchedule;
use App\Model\Functions\Player as FunctionPlayer;
use App\Model\Functions\Server as FunctionServer;
use PragmaRX\Google2FA\Google2FA;
use App\Utils\Argon;

define('SESSION_DURATION', 3600);

class Login extends Api
{
    public static function sendError($message, $code = 3): array
    {
        $returnMsg = [];
        $returnMsg["errorCode"] = $code;
        // 3 default error
        // 6 auth token
        // 8 email token
        $returnMsg["errorMessage"] = $message;
        return $returnMsg;
    }

    public static function selectAccount($request)
    {
        $postVars = $request->getPostVars();
        $request_type = $postVars['type'] ?? '';

        if (empty($request_type)) {
            return 'You are trying to access an unauthorized page.';
        }

        switch ($request_type) {
            case 'cacheinfo':
                return [
                    'playersonline' => (int) FunctionServer::getCountPlayersOnline(),
                    'twitchstreams' => 100,
                    'twitchviewer' => 100,
                    'gamingyoutubestreams' => 100,
                    'gamingyoutubeviewer' => 100
                ];

            case 'boostedcreature':
                $boostedCreature = FunctionServer::getBoostedCreature();
                $boostedBoss = FunctionServer::getBoostedBoss();

                return [
                    'creatureraceid' => (int) ($boostedCreature['raceid'] ?? 32),
                    'bossraceid' => (int) ($boostedBoss['raceid'] ?? 300),
                    'creaturename' => $boostedCreature['boostname'] ?? '',
                    'creaturelooktype' => (int) ($boostedCreature['looktype'] ?? 0),
                    'creaturelookhead' => (int) ($boostedCreature['lookhead'] ?? 0),
                    'creaturelookbody' => (int) ($boostedCreature['lookbody'] ?? 0),
                    'creaturelooklegs' => (int) ($boostedCreature['looklegs'] ?? 0),
                    'creaturelookfeet' => (int) ($boostedCreature['lookfeet'] ?? 0),
                    'creaturelookaddons' => (int) ($boostedCreature['lookaddons'] ?? 0),
                    'creaturelookmount' => (int) ($boostedCreature['lookmount'] ?? 0),
                    'creatureimageurl' => $boostedCreature['image_url'] ?? '',
                    'bossname' => $boostedBoss['boostname'] ?? '',
                    'bosslooktype' => (int) ($boostedBoss['looktype'] ?? 0),
                    'bosslookhead' => (int) ($boostedBoss['lookhead'] ?? 0),
                    'bosslookbody' => (int) ($boostedBoss['lookbody'] ?? 0),
                    'bosslooklegs' => (int) ($boostedBoss['looklegs'] ?? 0),
                    'bosslookfeet' => (int) ($boostedBoss['lookfeet'] ?? 0),
                    'bosslookaddons' => (int) ($boostedBoss['lookaddons'] ?? 0),
                    'bosslookmount' => (int) ($boostedBoss['lookmount'] ?? 0),
                    'bossimageurl' => $boostedBoss['image_url'] ?? '',
                ];

            case 'generatecharactername':
                $prefixes = ['Astar', 'Thor', 'Valer', 'Kael', 'Drak', 'Zephyr', 'Malik', 'Eldor'];
                $suffixes = ['us', 'on', 'ior', 'an', 'is', 'dor', 'en', 'os'];
                $genName = $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)];
                return [
                    'CharacterName' => $genName,
                    'GeneratedName' => $genName,
                    'name' => $genName,
                ];

            case 'checkcharactername':
                return [
                    'isvalid' => true,
                    'isavailable' => true,
                ];

            case 'checkemail':
                return [
                    'isvalid' => true,
                    'isavailable' => true,
                ];

            case 'checkpassword':
                return [
                    'isvalid' => true,
                ];

            case 'getaccountcreationstatus':
                return [
                    'RecommendedWorld' => 'OTServBR-Global',
                    'IsCaptchaDeactivated' => true,
                    'Worlds' => FunctionServer::getWorlds(),
                ];

            case 'eventschedule':
                return EventSchedule::getServerEvents();

            case 'news':
                return EntityCompendium::loadJsonCompendium();

            case 'login':
                $email = $postVars['email'] ?? '';
                $password = $postVars['password'] ?? '';
                $account = EntityAccount::getAccount(['email' => $email])->fetchObject();
                if (empty($account)) {
                    $account = EntityAccount::getAccount(['name' => $email])->fetchObject();
                }
                if (empty($account)) {
                    return self::sendError('Email or password is not correct.', 3);
                }
                if (!Argon::checkPassword($password, $account->password, $account->id)) {
                    return self::sendError('Password is not correct.', 3);
                }

                $authentication = EntityAccount::getAuthentication(['account_id' => $account->id])->fetchObject();
                if (!empty($authentication) and $authentication->status == 1) {
                    if (Argon::checkPassword($password, $account->password)) {
                        if (empty($postVars['token'])) {
                            return self::sendError('Two-factor token required for authentication.', 6);
                        }
                        $token = $postVars['token'];
                        $authentication = EntityAccount::getAuthentication(['account_id' => $account->id])->fetchObject();
                        $google2fa = new Google2FA();
                        $auth = $google2fa->verifyKey($authentication->secret, $token);
                        if ($auth != 1) {
                            return self::sendError('', 6);
                        }
                    }
                }

                $account_banned = Bans::getAccountBans(['account_id' => $account->id])->fetchObject();
                if (!empty($account_banned)) {
                    $expires_at = date('M d Y', $account_banned->expires_at);
                    $banned_by = EntityPlayer::getPlayer(['id' => $account_banned->banned_by])->fetchObject();
                    return self::sendError('Your account has been banned until ' . $expires_at . ' by ' . $banned_by->name, 3);
                }

                $sessionId = bin2hex(random_bytes(20));
                $hashedSessionId = hash('sha256', $sessionId);
                $expires = time() + SESSION_DURATION;

                EntityPlayer::deleteSessions($account->id);
                EntityPlayer::insertSessions([
                    'id' => $hashedSessionId,
                    'account_id' => (int) $account->id,
                    'expires' => $expires
                ]);

                $arrayWorlds = [];
                $worlds = ServerConfig::getWorlds();
                while ($world = $worlds->fetchObject()) {
                    $arrayWorlds[] = [
                        'id' => (int) $world->id,
                        'name' => $world->name,
                        'externaladdress' => $world->ip,
                        'externalport' => $world->port,
                        'externaladdressprotected' => $world->ip,
                        'externalportprotected' => $world->port,
                        'externaladdressunprotected' => $world->ip,
                        'externalportunprotected' => $world->port,
                        'previewstate' => 0,
                        'location' => FunctionServer::convertLocation($world->location),
                        'anticheatprotection' => false,
                        'pvptype' => 0,
                        'istournamentworld' => false,
                        'restrictedstore' => false,
                        'currenttournamentphase' => 2
                    ];
                }
                $arrayPlayers = [];
                $characters = EntityPlayer::getPlayer(['account_id' => $account->id]);
                while ($character = $characters->fetchObject()) {
                    if ($character->main == 1) {
                        $isMain = true;
                    } else {
                        $isMain = false;
                    }
                    $display_character = EntityPlayer::getDisplay(['player_id' => $character->id])->fetchObject();
                    if (empty($display_character)) {
                        $hidden = false;
                    } else {
                        if ($display_character->account == 1) {
                            $hidden = true;
                        } else {
                            $hidden = false;
                        }
                    }
                    $arrayPlayers[] = [
                        'worldid' => (int) $character->world,
                        'name' => $character->name,
                        'ismale' => (int) $character->sex,
                        'tutorial' => false,
                        'level' => (int) $character->level,
                        'vocation' => FunctionPlayer::convertVocation($character->vocation),
                        'outfitid' => (int) $character->looktype,
                        'headcolor' => (int) $character->lookhead,
                        'torsocolor' => (int) $character->lookbody,
                        'legscolor' => (int) $character->looklegs,
                        'detailcolor' => (int) $character->lookfeet,
                        'addonsflags' => (int) $character->lookaddons,
                        'ishidden' => $hidden,
                        'istournamentparticipant' => false,
                        'ismaincharacter' => $isMain,
                        'dailyrewardstate' => (int) $character->isreward,
                        'remainingdailytournamentplaytime' => 0,
                    ];
                }
                return [
                    'playdata' => [
                        'worlds' => $arrayWorlds,
                        'characters' => $arrayPlayers,
                    ],
                    'session' => [
                        'sessionkey' => $email . "\n" . $password,
                        'lastlogintime' => 0,
                        'ispremium' => $account ? true : false,
                        'premiumuntil' => $account ? 0 : (time() + ($account->premdays * 86400)),
                        'status' => 'active',
                        'returnernotification' => false,
                        'showrewardnews' => true,
                        'isreturner' => true,
                        'fpstracking' => false,
                        'optiontracking' => false,
                        'tournamentticketpurchasestate' => 0,
                        'emailcoderequest' => false,
                    ]
                ];

            default:
                self::sendError("Unrecognized event {$request_type}.", 3);
                exit;
        }
    }

    public static function getLogin($request): array|string|null
    {
        return self::selectAccount($request);
    }

}
