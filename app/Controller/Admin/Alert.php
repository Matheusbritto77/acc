<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Admin;

use App\Utils\View;

class Alert{

    /**
     * Method responsible for returning an error alert
     *
     * @param string $message
     * @return string
     */
    public static function getError($message)
    {
        return View::render('admin/alert/status', [
            'type' => 'danger',
            'message' => $message
        ]);
    }

    /**
     * Method responsible for returning a success alert
     *
     * @param string $message
     * @return string
     */
    public static function getSuccess($message)
    {
        return View::render('admin/alert/status', [
            'type' => 'success',
            'message' => $message
        ]);
    }

}