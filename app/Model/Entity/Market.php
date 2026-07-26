<?php
/**
 * Market Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Entity;

use App\DatabaseManager\Database;

class Market{
    public static function getMarketOffers($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('market_offers'))->select($where, $order, $limit, $fields);
    }

    public static function getMarketHistory($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('market_history'))->select($where, $order, $limit, $fields);
    }
}