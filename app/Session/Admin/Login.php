<?php
/**
 * Login Class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Session\Admin;

class Login{

    private static function isHttpsRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $requestScheme = $_SERVER['REQUEST_SCHEME'] ?? '';

        return $https === 'on'
            || $https === '1'
            || strtolower((string) $forwardedProto) === 'https'
            || strtolower((string) $requestScheme) === 'https';
    }

    public static function init()
    {
        if(session_status() != PHP_SESSION_ACTIVE){
            session_name(SITE_NAME);
            session_start([
                'cookie_httponly' => true,
                'cookie_secure' => self::isHttpsRequest(),
                'cookie_samesite' => 'Lax',
                'cookie_path' => '/',
                'use_strict_mode' => true,
            ]);
        }
    }

    public static function login($obAccount)
    {
        self::init();
        session_regenerate_id(true);
        $_SESSION['account']['user'] = [
            'id' => $obAccount->id,
            'name' => $obAccount->name,
            'email' => $obAccount->email
        ];
        $_SESSION['login_timeout'] = time();
        return true;
    }

    public static function isLogged()
    {
        self::init();
        if (isset($_SESSION['login_timeout'])) {
            if (time() - $_SESSION['login_timeout'] > 1800) {
                unset($_SESSION['login_timeout']);
                unset($_SESSION['account']['user']);
                return false;
            } else {
                return isset($_SESSION['account']['user']['id']);
            }
        } else {
            unset($_SESSION['account']['user']);
            return false;
        }
    }

    public static function idLogged()
    {
        self::init();
        return $_SESSION['account']['user']['id'] ?? null;
    }

    public static function logout()
    {
        self::init();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? true)
            );
        }

        session_destroy();
        return true;
    }
}
