<?php
/**
 * PremiumFeatures Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages;

use \App\Utils\View;
use App\Controller\Pages\Base;

class PremiumFeatures extends Base{

    public static function viewPremiumFeatures($request)
    {
        $content = View::render('pages/premiumfeatures', []);
        return parent::getBase('Premium Features', $content, $currentPage = 'premiumfeatures');
    }

}