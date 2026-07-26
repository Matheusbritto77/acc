<?php
/**
 * Uploads Class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Model\Entity;

use App\DatabaseManager\Database;

class Uploads
{

    public static function getUploads($where = null, $order = null, $limit = null, $fields = '*'){
        return (new Database('canary_uploads'))->select($where, $order, $limit, $fields);
    }

    public static function insertUploads($values = null){
        return (new Database('canary_uploads'))->insert($values);
    }

    public static function updateUploads($where = null, $values = null){
        return (new Database('canary_uploads'))->update($where, $values);
    }

    public static function deleteUploads($where = null){
        return (new Database('canary_uploads'))->delete($where);
    }

}