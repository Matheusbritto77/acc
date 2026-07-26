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

class Characters{
    public static function getCharacter($where = null, $like = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('players'))->selectLike($where, $like, $order, $limit, $fields);
    }

    public static function getCharacterCount($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('players'))->select($where, $order, $limit, $fields);
    }
}