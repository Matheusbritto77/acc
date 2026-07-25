<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Functions;

class Website{
    public static function getStyleCode($i)
    {
        return is_int($i / 2) ? '#D4C0A1' : '#F1E0C6';
    }

    public static function getStyleCss($i)
    {
        return is_int($i / 2) ? 'Odd' : 'Even';
    }

    public static function filterArray($array, $filter)
    {
        usort($array, function($a, $b) use ($filter){
            return $a[$filter] > $b[$filter];
        });
        return $array;
    }
}