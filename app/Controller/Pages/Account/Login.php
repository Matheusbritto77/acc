<?php
/**
 * Login Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Account;

use App\Utils\Argon;
use App\Utils\View;
use App\Http\Request;
use App\Controller\Pages\Base;
use App\Model\Entity\Login as EntityLogin;
use App\Session\Admin\Login as SessionAdminLogin;
use App\Controller\Admin\Alert;
use App\Model\Entity\Account;
use App\Security\LoginRateLimiter;
use App\Model\Functions\FunMailer;
use PragmaRX\Google2FA\Google2FA;

class Login extends Base{

    /**
     * Method responsible for returning the login page rendering
     *
     * @param Request $request
     * @param string|null $errorMessage
     * @return string
     */
    public static function getLogin(Request $request, ?string $errorMessage = null, bool $showAuthenticator = false): string
    {
        $status = !is_null($errorMessage);
        $statusMessage = $errorMessage === 'true'
            ? 'You have entered a wrong password or email address.'
            : $errorMessage;

        $content = View::render('pages/account/login', [
            'status' => $status,
            'status_message' => $statusMessage,
            'show_authenticator' => $showAuthenticator,
        ]);

        return parent::getBase('Account Management', $content, 'account');
    }

    /**
     * Method responsible for setting user login
     *
     * @param Request $request
     */
    public static function setLogin(Request $request)
    {
        $postVars = $request->getPostVars();
        $email = $postVars['loginemail'] ?? '';
        $pass = $postVars['loginpassword'] ?? '';
        $ipAddress = $request->getClientIp();
        $retryAfter = LoginRateLimiter::getRetryAfter('account-web', $email, $ipAddress);

        if ($retryAfter > 0) {
            return self::getLogin($request, LoginRateLimiter::formatRetryMessage($retryAfter));
        }

        $filter_email = filter_var($email, FILTER_VALIDATE_EMAIL);
        if(!$filter_email){
            LoginRateLimiter::registerFailure('account-web', $email, $ipAddress);
            return self::getLogin($request, 'true');
        }

        // Verify email
        $obAccount = EntityLogin::getLoginbyEmail($email);
        if(!$obAccount instanceof EntityLogin){
            LoginRateLimiter::registerFailure('account-web', $email, $ipAddress);
            return self::getLogin($request, 'true');
        }

        // Password verify by sha1
        if(!Argon::checkPassword($pass, $obAccount->password, $obAccount->id)){
            LoginRateLimiter::registerFailure('account-web', $email, $ipAddress);
            return self::getLogin($request, 'true');
        }

        $emailVerification = Account::getEmailVerification(['account_id' => $obAccount->id])->fetchObject();
        if (!empty($emailVerification) && (int) $emailVerification->status !== 1) {
            return self::getLogin($request, 'Please verify your email address before logging in.');
        }

        $authentication = Account::getAuthentication([ 'account_id' => $obAccount->id])->fetchObject();
        if (!empty($authentication)) {
            if ($authentication->status == 1) {
                if (empty($postVars['token'])) {
                    return self::getLogin($request, 'You need to enter your authenticator token.', true);
                }
                $google2fa = new Google2FA();
                $auth = $google2fa->verifyKey($authentication->secret, $postVars['token']);
                if ($auth != 1) {
                    return self::getLogin($request, 'The authenticator token is invalid.', true);
                }
            }
        }

        LoginRateLimiter::clear('account-web', $email, $ipAddress);
        SessionAdminLogin::login($obAccount);
        FunMailer::sendLoginNotification(
            (string) $obAccount->email,
            (string) ($obAccount->name ?? $obAccount->email),
            (string) $ipAddress
        );
        return $request->getRouter()->redirect('/account');
    }

    public static function setLogout($request): string
    {
        SessionAdminLogin::logout();
        $content = View::render('pages/account/logout', []);
        return parent::getBase('Logout Successful', $content, 'account');
    }

}
