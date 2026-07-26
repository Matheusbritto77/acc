<?php
/**
 * Rules Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Pages\Support;

use \App\Utils\View;
use App\Controller\Pages\Base;

class Rules extends Base{

    public static function viewRules($request)
    {
        $content = View::render('pages/support/rules', []);
        return parent::getBase('Rules', $content, $currentPage = 'rules');
    }

}