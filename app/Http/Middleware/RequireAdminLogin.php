<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Session\Admin\Login as SessionAdminLogin;

class RequireAdminLogin{
    
    public static function handle($request, $next)
    {
        if(!SessionAdminLogin::isLogged()){
            $request->getRouter()->redirect('/admin/login');
        }
        return $next($request);
    }
    
}