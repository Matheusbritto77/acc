<?php
/**
 * Lost Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Account;

use App\Controller\Pages\Base;
use App\Utils\View;
use App\Model\Entity\Account as EntityAccount;
use App\Model\Entity\Player as EntityPlayer;
use App\Utils\Argon;
use App\Model\Functions\FunMailer;

class Lost extends Base{

    private static function resolveAccountByIdentifier(string $identifier)
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $account = EntityAccount::getAccount(['email' => $identifier])->fetchObject();
            if ($account) {
                return $account;
            }
        }

        $player = EntityPlayer::getPlayer(['name' => $identifier])->fetchObject();
        if ($player) {
            return EntityAccount::getAccount(['id' => $player->account_id])->fetchObject();
        }

        return EntityAccount::getAccount(['name' => $identifier])->fetchObject();
    }

    public static function viewRecoveryKey($request)
    {
        $postVars = $request->getPostVars();
        if(empty($postVars['key1']) or empty($postVars['key2']) or empty($postVars['key3']) or empty($postVars['key4'])){
            $request->getRouter()->redirect('/account/lostaccount');
        }
        $filter_key1 = filter_var($postVars['key1'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_key2 = filter_var($postVars['key2'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_key3 = filter_var($postVars['key3'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_key4 = filter_var($postVars['key4'], FILTER_SANITIZE_SPECIAL_CHARS);
        $recoverykey = $filter_key1 . '-' . $filter_key2 . '-' . $filter_key3 . '-' . $filter_key4;

        if (empty($postVars['email'])) {
            $request->getRouter()->redirect('/account/lostaccount');
        }
        $filter_email = filter_var($postVars['email'], FILTER_SANITIZE_SPECIAL_CHARS);
        $account = self::resolveAccountByIdentifier($filter_email);
        if($account == false){
            $request->getRouter()->redirect('/account/lostaccount');
        }

        $account_recoverykey = EntityAccount::getAccountRegistration(['account_id' => $account->id])->fetchObject();
        if (empty($account_recoverykey) || empty($account_recoverykey->recovery)) {
            return self::getLostAccount($request, 'This account does not have a recovery key registered.');
        }

        if (empty($postVars['newpassword'])) {
            $request->getRouter()->redirect('/account/lostaccount');
        }
        if (empty($postVars['newpasswordconfirm'])) {
            $request->getRouter()->redirect('/account/lostaccount');
        }
        $filter_password = filter_var($postVars['newpassword'], FILTER_SANITIZE_SPECIAL_CHARS);
        $filter_passwordconfirm = filter_var($postVars['newpasswordconfirm'], FILTER_SANITIZE_SPECIAL_CHARS);
        if ($filter_password != $filter_passwordconfirm) {
            $request->getRouter()->redirect('/account/lostaccount');
        }
        $new_password = Argon::generateArgonPassword($filter_password);

        if (strtoupper((string) $account_recoverykey->recovery) === strtoupper($recoverykey)) {
            EntityAccount::updateAccount([ 'id' => $account->id], [
                'password' => $new_password
            ]);
            FunMailer::sendRecoverySuccess(
                (string) $account->email,
                (string) ($account->name ?? $account->email)
            );
            $request->getRouter()->redirect('/account/login');
        }
        return self::getLostAccount($request, 'The recovery key you entered is invalid.');
    }

    public static function selectAccount($request)
    {
        $postVars = $request->getPostVars();
        $identifier = $postVars['email'] ?? '';
        $filter_identifier = filter_var($identifier, FILTER_SANITIZE_SPECIAL_CHARS);
        if ($filter_identifier === '') {
            return self::getLostAccount($request, 'You need to enter a character name or email address.');
        }

        $account = self::resolveAccountByIdentifier($filter_identifier);
        if($account == false){
            return self::getLostAccount($request, 'Account not found.');
        }

        $accountRegistration = EntityAccount::getAccountRegistration(['account_id' => $account->id])->fetchObject();
        if (empty($accountRegistration) || empty($accountRegistration->recovery)) {
            return self::getLostAccount($request, 'This account does not have a recovery key registered.');
        }

        if (!FunMailer::sendRecoveryRequest(
            (string) $account->email,
            (string) ($account->name ?? $account->email),
            (string) $accountRegistration->recovery
        )) {
            return self::getLostAccount($request, 'We could not send the recovery email. Please try again later.');
        }
        
        $content = View::render('pages/account/lostaccount_first', [
            'email' => $account->email,
            'account_name' => $account->name ?? $account->email,
            'status' => 'A recovery email was sent to your account email address.',
        ]);
        return parent::getBase('Lost Account', $content, 'lostaccount');
    }

    public static function getLostAccount($request, $status = null)
    {
        $content = View::render('pages/account/lostaccount', [
            'status' => $status,
        ]);
        return parent::getBase('Lost Account', $content, 'lostaccount');
    }

}
