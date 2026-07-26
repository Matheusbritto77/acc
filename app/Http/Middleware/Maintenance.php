<?php
/**
 * Validator class
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Http\Request;
use Closure;
use App\Http\Response;
use Exception;

class Maintenance{

    /**
     * Method responsible for running the middleware
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, $next)
    {
        // Check website maintenance status
        if($_ENV['MAINTENANCE'] == 'true'){
            throw new Exception('Página em manutenção. Tente novamente mais tarde.', 200);
        }

        // Runs the next level of middleware
        return $next($request);
    }

}