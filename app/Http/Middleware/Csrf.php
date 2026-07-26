<?php
/**
 * CSRF middleware
 *
 * @package   astarOT
 * @author    britto dev <lucasgiovannidesigner@gmail.com>
 * @copyright 2022 astarOT
 */

namespace App\Http\Middleware;

use App\Http\Response;
use App\Security\Csrf as CsrfSecurity;

class Csrf
{
    public function handle($request, $next)
    {
        $method = strtoupper((string) $request->getHttpMethod());
        $uri = (string) $request->getUri();

        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true) || strpos($uri, '/admin') !== 0) {
            return $next($request);
        }

        $postVars = $request->getPostVars();
        $headers = $request->getHeaders();
        $token = $postVars['_csrf_token'] ?? $headers['X-CSRF-TOKEN'] ?? $headers['x-csrf-token'] ?? null;

        if (!CsrfSecurity::validate(is_string($token) ? $token : null)) {
            return new Response(419, 'Token CSRF inválido. Atualize a página e tente novamente.');
        }

        return $next($request);
    }
}
