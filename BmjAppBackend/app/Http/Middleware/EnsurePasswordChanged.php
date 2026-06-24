<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Blocks authenticated requests while the user is still on an admin-issued temporary
     * password (must_change_password = true), except the few routes needed to change it
     * or sign out. This makes a temporary password single-use in effect: it authenticates
     * you only far enough to set a real password.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            $allowed = [
                'user/changePassword',
                'logout',
                'user', // let the SPA read the current user to discover it must redirect
            ];

            if (!in_array($request->path(), $this->withApiPrefix($allowed), true)) {
                return response()->json([
                    'message' => 'You must change your temporary password before continuing.',
                    'must_change_password' => true,
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }

    private function withApiPrefix(array $paths): array
    {
        return array_map(fn ($p) => 'api/' . ltrim($p, '/'), $paths);
    }
}
