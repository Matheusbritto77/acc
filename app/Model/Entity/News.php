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

class News{

    public static function getNews($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('canary_news'))->select($where, $order, $limit, $fields);
    }

    public static function insertNews($values = null){
        return (new Database('canary_news'))->insert($values);
    }

    public static function insertNewsOrFail($values = null){
        return (new Database('canary_news'))->insertOrFail($values);
    }

    public static function updateNews($values = null){
        return (new Database('canary_news'))->update('', $values);
    }
    
}
