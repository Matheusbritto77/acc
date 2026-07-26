<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Utils;

use App\Model\Functions\Website;
use Twig\TwigFilter;

class ViewFilters{
    public static function addFilters()
    {
        $filter = new TwigFilter('exp', function($exp){
            return number_format($exp, '2', '.', '');
        });

        return $filter;
    }
}