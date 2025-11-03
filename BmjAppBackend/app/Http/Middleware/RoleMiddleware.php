<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
         $user = $request->user();

        if (!$user) {
            abort(403, 'Unauthorized');
        }

        $userRole = str_replace(' ', '_', strtolower($user->role));
        $roles = array_map(fn($r) => str_replace(' ', '_', strtolower($r)), $roles);

        if (!in_array($userRole, $roles) && $userRole !== 'director') {
            abort(403, 'Unauthorized');
        }

        return $next($request);
    }
}
