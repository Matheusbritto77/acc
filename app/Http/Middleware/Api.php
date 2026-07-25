<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    Lucas Giovanni <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Http\Request;
use Closure;
use App\Http\Response;
use Exception;

class Api{

    /**
     * Method responsible for running the middleware
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, $next)
    {
        $request->getRouter()->setContentType('application/json');

        // Runs the next level of middleware
        return $next($request);
    }

}