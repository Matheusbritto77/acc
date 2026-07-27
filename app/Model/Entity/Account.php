<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Entity;

use App\DatabaseManager\Database;

#[\AllowDynamicProperties]
class Account{
    
    public static function getAccount($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('accounts'))->select($where, $order, $limit, $fields);
    }

    public static function getAccountRegistration($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('account_registration'))->select($where, $order, $limit, $fields);
    }

    public static function insertRegister($values = null){
        return (new Database('account_registration'))->insert($values);
    }

    public static function updateRegister($where = null, $values = null){
        return (new Database('account_registration'))->update($where, $values);
    }

    public static function updateAccount($where = null, $values = null){
        return (new Database('accounts'))->update($where, $values);
    }

    public static function deleteAccount($where = null){
        return (new Database('accounts'))->delete($where);
    }

    public static function getEmailVerification($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('account_email_verification'))->select($where, $order, $limit, $fields);
    }

    public static function insertEmailVerification($values = null){
        return (new Database('account_email_verification'))->insert($values);
    }

    public static function updateEmailVerification($where = null, $values = null){
        return (new Database('account_email_verification'))->update($where, $values);
    }

    public static function deleteEmailVerification($where = null){
        return (new Database('account_email_verification'))->delete($where);
    }

    public static function getAuthentication($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('account_authentication'))->select($where, $order, $limit, $fields);
    }

    public static function insertAuthentication($values = null){
        return (new Database('account_authentication'))->insert($values);
    }

    public static function updateAuthentication($where = null, $values = null){
        return (new Database('account_authentication'))->update($where, $values);
    }

    public static function deleteAuthentication($where = null){
        return (new Database('account_authentication'))->delete($where);
    }
    
}
