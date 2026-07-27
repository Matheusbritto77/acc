<?php
/**
 * ChangePassword Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Account;

use \App\Utils\View;
use App\Controller\Pages\Base;
use App\Model\Entity\Player as EntityPlayer;
use App\Model\Entity\Account as EntityAccount;
use App\Session\Admin\Login as SessionAdminLogin;
use App\Utils\Argon;
use App\Model\Functions\FunMailer;

class ChangePassword extends Base{

    public static function updatePassword($request)
    {
        $postVars = $request->getPostVars();

        $newpassword = $postVars['newpassword'] ?? '';
        $filter_newpassword = filter_var($newpassword, FILTER_SANITIZE_SPECIAL_CHARS);
        $old_password = $postVars['oldpassword'] ?? '';
        $filter_oldpassword = filter_var($old_password, FILTER_SANITIZE_SPECIAL_CHARS);

        if(SessionAdminLogin::isLogged() != true){
            return self::viewChangePassword($request, 'You are not logged in.');
        }
        if(empty($newpassword)){
            return self::viewChangePassword($request);
        }
        if(empty($old_password)){
            return self::viewChangePassword($request);
        }
        $AccountId = SessionAdminLogin::idLogged();
        $account = EntityPlayer::getAccount([ 'id' => $AccountId])->fetchObject();
        if (!$account) {
            return self::viewChangePassword($request, 'Account not found.');
        }
        if (!Argon::checkPassword($filter_oldpassword, $account->password, $account->id)) {
            return self::viewChangePassword($request, 'Invalid password.');
        }

        EntityAccount::updateAccount([ 'id' => $AccountId], [
            'password' => Argon::generateArgonPassword($filter_newpassword),
        ]);
        FunMailer::sendPasswordChanged((string) $account->email, (string) ($account->name ?? $account->email));
        $request->getRouter()->redirect('/account/logout');
    }

    public static function viewChangePassword($request, $status = null)
    {
        $content = View::render('pages/account/changepassword', [
            'status' => $status,
        ]);
        return parent::getBase('Account Management', $content, 'account');
    }

}
