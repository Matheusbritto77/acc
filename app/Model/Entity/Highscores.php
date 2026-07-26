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
    class Highscores{
        public static function getHighscoresEntity($where = null, $order = null, $limit = null, $fields = '*'){
            return (new Database('players'))->select($where, $order, $limit, $fields);
        }
    }