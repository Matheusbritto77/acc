<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Model\Entity\Account;
use App\Session\Admin\Login as SessionAdminLogin;

class RolePermission{
    
    public static function handle($request, $next)
    {
        $select_account = Account::getAccount(['id' => SessionAdminLogin::idLogged()], null, 1, 'page_access')->fetchObject();
        if(!$select_account || (int) $select_account->page_access <= 0){
            SessionAdminLogin::logout();
            $request->getRouter()->redirect('/admin/login');
        }
        return $next($request);
    }
    
}
