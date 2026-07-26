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

class Badges{
    public static function getPlayerBadges($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('player_badges'))->select($where, $order, $limit, $fields);
    }

    public static function getServerBadges($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('canary_badges'))->select($where, $order, $limit, $fields);
    }
}