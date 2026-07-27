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
    private const PENDING_LOGIN_SESSION_KEY = 'pending_login';
    private const PENDING_LOGIN_TTL = 600;

    private static function getPendingLoginData(): ?array
    {
        SessionAdminLogin::init();
        $pendingLogin = $_SESSION['account'][self::PENDING_LOGIN_SESSION_KEY] ?? null;
        if (!is_array($pendingLogin)) {
            return null;
        }

        if (empty($pendingLogin['account_id']) || empty($pendingLogin['created_at'])) {
            self::clearPendingLoginData();
            return null;
        }

        if (((int) $pendingLogin['created_at']) < (time() - self::PENDING_LOGIN_TTL)) {
            self::clearPendingLoginData();
            return null;
        }

        return $pendingLogin;
    }

    private static function setPendingLoginData(int $accountId, string $email, string $accountName): void
    {
        SessionAdminLogin::init();
        $_SESSION['account'][self::PENDING_LOGIN_SESSION_KEY] = [
            'account_id' => $accountId,
            'email' => $email,
            'account_name' => $accountName,
            'created_at' => time(),
        ];
    }

    private static function clearPendingLoginData(): void
    {
        SessionAdminLogin::init();
        unset($_SESSION['account'][self::PENDING_LOGIN_SESSION_KEY]);
    }

    /**
     * Method responsible for returning the login page rendering
     *
     * @param Request $request
     * @param string|null $errorMessage
     * @return string
     */
    public static function getLogin(Request $request, ?string $errorMessage = null, bool $showAuthenticator = false): string
    {
        $pendingLogin = self::getPendingLoginData();
        $showAuthenticator = $showAuthenticator || !empty($pendingLogin);
        $status = !is_null($errorMessage);
        $statusMessage = $errorMessage === 'true'
            ? 'You have entered a wrong password or email address.'
            : $errorMessage;

        $content = View::render('pages/account/login', [
            'status' => $status,
            'status_message' => $statusMessage,
            'show_authenticator' => $showAuthenticator,
            'pending_account_name' => $pendingLogin['account_name'] ?? '',
            'submit_label' => $showAuthenticator ? 'Continue' : 'Login',
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
        SessionAdminLogin::init();
        $postVars = $request->getPostVars();
        $pendingLogin = self::getPendingLoginData();
        $ipAddress = $request->getClientIp();

        if (!empty($pendingLogin)) {
            $pendingEmail = (string) ($pendingLogin['email'] ?? '');
            $retryAfter = LoginRateLimiter::getRetryAfter('account-web', $pendingEmail, $ipAddress);
            if ($retryAfter > 0) {
                return self::getLogin($request, LoginRateLimiter::formatRetryMessage($retryAfter), true);
            }

            $token = trim((string) ($postVars['token'] ?? ''));
            if ($token === '') {
                return self::getLogin($request, 'You need to enter your authenticator token.', true);
            }

            $obAccount = Account::getAccount(['id' => (int) $pendingLogin['account_id']])->fetchObject();
            if (empty($obAccount)) {
                self::clearPendingLoginData();
                return self::getLogin($request, 'true');
            }

            $emailVerification = Account::getEmailVerification(['account_id' => $obAccount->id])->fetchObject();
            if (!empty($emailVerification) && (int) $emailVerification->status !== 1) {
                self::clearPendingLoginData();
                return self::getLogin($request, 'Please verify your email address before logging in.');
            }

            $authentication = Account::getAuthentication(['account_id' => $obAccount->id])->fetchObject();
            if (empty($authentication) || (int) $authentication->status !== 1) {
                self::clearPendingLoginData();
                return self::getLogin($request, 'true');
            }

            $google2fa = new Google2FA();
            if ($google2fa->verifyKey($authentication->secret, $token) != 1) {
                LoginRateLimiter::registerFailure('account-web', $pendingEmail, $ipAddress);
                return self::getLogin($request, 'The authenticator token is invalid.', true);
            }

            LoginRateLimiter::clear('account-web', $pendingEmail, $ipAddress);
            self::clearPendingLoginData();
            SessionAdminLogin::login($obAccount);
            FunMailer::sendLoginNotification(
                (string) $obAccount->email,
                (string) ($obAccount->name ?? $obAccount->email),
                (string) $ipAddress
            );
            return $request->getRouter()->redirect('/account');
        }

        $email = $postVars['loginemail'] ?? '';
        $pass = $postVars['loginpassword'] ?? '';
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
                self::setPendingLoginData((int) $obAccount->id, (string) $obAccount->email, (string) ($obAccount->name ?? $obAccount->email));
                $token = trim((string) ($postVars['token'] ?? ''));
                if ($token === '') {
                    return self::getLogin($request, 'Enter your authenticator token to continue.', true);
                }
                $google2fa = new Google2FA();
                $auth = $google2fa->verifyKey($authentication->secret, $token);
                if ($auth != 1) {
                    LoginRateLimiter::registerFailure('account-web', $email, $ipAddress);
                    return self::getLogin($request, 'The authenticator token is invalid.', true);
                }
            }
        }

        LoginRateLimiter::clear('account-web', $email, $ipAddress);
        self::clearPendingLoginData();
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
