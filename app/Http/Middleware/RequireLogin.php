<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Session\Admin\Login as SessionPlayerLogin;

class RequireLogin{
    
    public static function handle($request, $next)
    {
        if(!SessionPlayerLogin::isLogged()){
            $request->getRouter()->redirect('/account/login');
        }
        return $next($request);
    }
    
}