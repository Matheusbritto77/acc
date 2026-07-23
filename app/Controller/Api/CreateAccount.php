<?php

namespace App\Controller\Api;

use App\Model\Entity\CreateAccount as EntityCreateAccount;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Entity\Worlds as EntityWorlds;
use App\Model\Entity\ServerConfig as EntityServerConfig;
use App\Model\Functions\Server as FunctionServer;
use App\Utils\Argon;

class CreateAccount extends Api
{
    public static function handle($request)
    {
        $postVars = $request->getPostVars();
        if (empty($postVars)) {
            $rawInput = file_get_contents('php://input');
            $postVars = json_decode($rawInput, true) ?? [];
        }

        $type = $postVars['type'] ?? '';

        switch ($type) {
            case 'getaccountcreationstatus':
                $worlds = [];
                $selectWorlds = EntityWorlds::getWorlds();
                while ($w = $selectWorlds->fetchObject()) {
                    $worlds[] = [
                        'Name' => $w->name,
                        'Region' => FunctionServer::convertLocation($w->location),
                        'PvPType' => FunctionServer::convertPvpType($w->pvp_type),
                        'PlayersOnline' => (int)FunctionServer::getCountPlayersOnline()
                    ];
                }

                if (empty($worlds)) {
                    $worlds[] = [
                        'Name' => 'OTServBR-Global',
                        'Region' => 'South America',
                        'PvPType' => 'Open PvP',
                        'PlayersOnline' => 0
                    ];
                }

                return [
                    'RecommendedWorld' => $worlds[0]['Name'],
                    'IsCaptchaDeactivated' => true,
                    'Worlds' => $worlds
                ];

            case 'checkcharactername':
                $name = $postVars['CharacterName'] ?? '';
                if (empty($name)) {
                    return ['IsAvailable' => false, 'errorMessage' => 'Character name is empty.'];
                }
                $filter_name = filter_var($name, FILTER_SANITIZE_SPECIAL_CHARS);
                if (strlen($filter_name) < 5 || strlen($filter_name) > 29) {
                    return ['IsAvailable' => false, 'errorMessage' => 'Name must be between 5 and 29 characters.'];
                }
                $verify = EntityPlayer::getPlayer(['name' => $filter_name])->fetchObject();
                if (!empty($verify)) {
                    return ['IsAvailable' => false, 'errorMessage' => 'This character name is already in use.'];
                }
                return ['IsAvailable' => true];

            case 'checkemail':
                $email = $postVars['Email'] ?? '';
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['IsValid' => false, 'errorMessage' => 'Invalid email address format.'];
                }
                $filter_email = filter_var($email, FILTER_SANITIZE_SPECIAL_CHARS);
                $verify = EntityPlayer::getAccount(['email' => $filter_email])->fetchObject();
                if (!empty($verify)) {
                    return ['IsValid' => false, 'errorMessage' => 'This email is already in use.'];
                }
                return ['IsValid' => true];

            case 'checkpassword':
                $password = $postVars['Password1'] ?? '';
                if (strlen($password) < 10 || strlen($password) > 29) {
                    return [
                        'PasswordValid' => false,
                        'PasswordStrength' => 1,
                        'PasswordStrengthColor' => '#EC644B',
                        'PasswordRequirements' => [
                            'HasUpperCase' => (preg_match('/[A-Z]/', $password) === 1),
                            'HasNumber' => (preg_match('/[0-9]/', $password) === 1),
                            'PasswordLength' => false,
                            'InvalidCharacters' => false,
                            'HasLowerCase' => (preg_match('/[a-z]/', $password) === 1)
                        ]
                    ];
                }
                return [
                    'PasswordValid' => true,
                    'PasswordStrength' => 3,
                    'PasswordStrengthColor' => '#76EE00',
                    'PasswordRequirements' => [
                        'HasUpperCase' => true,
                        'HasNumber' => true,
                        'PasswordLength' => true,
                        'InvalidCharacters' => false,
                        'HasLowerCase' => true
                    ]
                ];

            case 'createaccountandcharacter':
                $email = $postVars['EMail'] ?? '';
                $password = $postVars['Password'] ?? '';
                $charName = $postVars['CharacterName'] ?? '';
                $charSex = (int)($postVars['CharacterSex'] ?? 1);

                // Generate a randomized account name since OTClient only asks for Email and Password
                // Use a secure prefix like "ACC" followed by a unique number or random hex
                $accName = 'ACC' . rand(100000, 999999);
                while (!empty(EntityPlayer::getAccount(['name' => $accName])->fetchObject())) {
                    $accName = 'ACC' . rand(100000, 999999);
                }

                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return ['Success' => false, 'errorMessage' => 'Invalid email address.'];
                }
                if (empty($password)) {
                    return ['Success' => false, 'errorMessage' => 'Invalid password.'];
                }

                $filter_email = filter_var($email, FILTER_SANITIZE_SPECIAL_CHARS);
                if (!empty(EntityPlayer::getAccount(['email' => $filter_email])->fetchObject())) {
                    return ['Success' => false, 'errorMessage' => 'This email is already registered.'];
                }

                $filter_name = filter_var($charName, FILTER_SANITIZE_SPECIAL_CHARS);
                if (strlen($filter_name) < 5 || strlen($filter_name) > 29) {
                    return ['Success' => false, 'errorMessage' => 'Character name must be between 5 and 29 characters.'];
                }
                if (!empty(EntityPlayer::getPlayer(['name' => $filter_name])->fetchObject())) {
                    return ['Success' => false, 'errorMessage' => 'Character name is already in use.'];
                }

                // Vocation selection: standard rookgaard/no-vocation sample (vocation 0)
                $vocation = 0;
                $playerSample = EntityCreateAccount::getPlayerSamples(['vocation' => $vocation])->fetchObject();
                if (empty($playerSample)) {
                    // Fallback to any sample if vocation 0 doesn't exist
                    $playerSample = EntityCreateAccount::getPlayerSamples()->fetchObject();
                }

                // Find a default world
                $selectWorlds = EntityWorlds::getWorlds()->fetchObject();
                if (empty($selectWorlds)) {
                    return ['Success' => false, 'errorMessage' => 'No game world is available.'];
                }

                $convertPassword = Argon::generateArgonPassword($password);
                $account = [
                    'name' => $accName,
                    'password' => $convertPassword,
                    'email' => $filter_email,
                    'page_access' => '0',
                    'premdays' => '0',
                    'type' => '0',
                    'coins' => '0',
                    'recruiter' => '0',
                ];

                $accountId = EntityCreateAccount::createAccount($account);

                // Map client gender values (usually 1 = male, 0 = female or standard 1/0)
                // In Canary: 1 = male, 0 = female
                $sex = ($charSex === 0) ? 0 : 1;

                $character = [
                    'name' => $filter_name,
                    'group_id' => '1',
                    'account_id' => $accountId,
                    'main' => '1',
                    'level' => $playerSample->level,
                    'vocation' => $playerSample->vocation,
                    'health' => $playerSample->health,
                    'healthmax' => $playerSample->healthmax,
                    'experience' => $playerSample->experience,
                    'lookbody' => $playerSample->lookbody,
                    'lookfeet' => $playerSample->lookfeet,
                    'lookhead' => $playerSample->lookhead,
                    'looklegs' => $playerSample->looklegs,
                    'looktype' => $playerSample->looktype,
                    'lookaddons' => $playerSample->lookaddons,
                    'maglevel' => $playerSample->maglevel,
                    'mana' => $playerSample->mana,
                    'manamax' => $playerSample->manamax,
                    'manaspent' => $playerSample->manaspent,
                    'soul' => $playerSample->soul,
                    'town_id' => $playerSample->town_id,
                    'world' => $selectWorlds->id,
                    'posx' => $playerSample->posx,
                    'posy' => $playerSample->posy,
                    'posz' => $playerSample->posz,
                    'cap' => $playerSample->cap,
                    'sex' => $sex,
                    'balance' => $playerSample->balance,
                    'istutorial' => '1',
                    'conditions' => '',
                ];

                EntityCreateAccount::createCharacter($character);

                return ['Success' => true];

            default:
                return ['Success' => false, 'errorMessage' => 'Invalid action type.'];
        }
    }
}
