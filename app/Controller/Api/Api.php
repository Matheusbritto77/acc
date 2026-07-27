<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Controller\Api;

use App\Http\Request;
use App\Model\Functions\Server as FunctionsServer;

class Api{

    /**
     * Método responsável por retornar os detalhes da API
     *
     * @param Request $request
     * @return array
     */
    public static function getDetails($request)
    {
        return [
            'name' => 'API astarOT',
            'version' => 'v1.0.0',
            'author' => 'britto dev',
            'email' => 'contato@lucasgiovanni.com'
        ];
    }

    public static function getStatus()
    {
        $serverStatus = FunctionsServer::getServerStatus();
        $playersOnline = (int) FunctionsServer::getCountPlayersOnline();
        $playersRecord = FunctionsServer::getRecordPlayersWorlds();

        return [
            'server_name' => SITE_NAME,
            'website' => URL,
            'server_status' => $serverStatus,
            'online' => $serverStatus === 'Server Online',
            'players_online' => $playersOnline,
            'players_record' => [
                'value' => is_array($playersRecord) && isset($playersRecord['record']) ? (int) $playersRecord['record'] : 0,
                'timestamp' => is_array($playersRecord) && isset($playersRecord['timestamp']) ? $playersRecord['timestamp'] : null,
            ],
            'generated_at' => gmdate(DATE_ATOM),
        ];
    }

    protected static function getPagination($request, $obPagination)
    {
        $queryParams = $request->getQueryParams();
        $pages = $obPagination->getPages();

        return [
            'current' => isset($queryParams['page']) ? (int)$queryParams['page'] : 1,
            'total' => !empty($pages) ? count($pages) : 1
        ];
    }
    
}
