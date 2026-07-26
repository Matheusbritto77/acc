<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Admin;

use App\Model\Entity\Login as EntityLogin;
use App\Utils\Argon;
use App\Utils\View;
use App\Http\Request;
use App\Session\Admin\Login as SessionAdminLogin;
use App\Controller\Admin\Alert;
use App\Security\LoginRateLimiter;

class Login extends Base{

    /**
     * Method responsible for returning the login page rendering
     *
     * @param Request $request
     * @param string $errorMessage
     * @return string
     */
    public static function getLogin($request, $errorMessage = null)
    {
        // Login status
        $status = !is_null($errorMessage) ? Alert::getError($errorMessage) : '';

        // Render login page and $status
        return $content = View::render('admin/login', [
            'title' => 'Login - astarOT',
            'status' => $status
        ]);

        //return parent::getPanel('Login', $content, 'home');
    }

    /**
     * Method responsible for setting user login
     *
     * @param Request $request
     */
    public static function setLogin($request)
    {
        $postVars = $request->getPostVars();
        $email = $postVars['login-email'] ?? '';
        $pass = $postVars['login-password'] ?? '';
        $ipAddress = $request->getClientIp();
        $retryAfter = LoginRateLimiter::getRetryAfter('admin-web', $email, $ipAddress);

        if ($retryAfter > 0) {
            return self::getLogin($request, LoginRateLimiter::formatRetryMessage($retryAfter));
        }

        $obAccount = EntityLogin::getLoginbyEmail($email);

        // Verify email
        if(!$obAccount instanceof EntityLogin){
            LoginRateLimiter::registerFailure('admin-web', $email, $ipAddress);
            return self::getLogin($request, 'Email inválidos.');
        }

        // Password verify by sha1
        if(!Argon::checkPassword($pass, $obAccount->password, $obAccount->id)){
            LoginRateLimiter::registerFailure('admin-web', $email, $ipAddress);
            return self::getLogin($request, 'Password inválidos.');
        }

        // Verify account access
        if(!($obAccount->page_access > 0)){
            LoginRateLimiter::registerFailure('admin-web', $email, $ipAddress);
            return self::getLogin($request, 'Você não tem acesso.');
        }

        LoginRateLimiter::clear('admin-web', $email, $ipAddress);
        SessionAdminLogin::login($obAccount);

        $request->getRouter()->redirect('/admin');
    }

    public static function setLogout($request)
    {
        SessionAdminLogin::logout();

        $request->getRouter()->redirect('/admin/login');
    }

}
