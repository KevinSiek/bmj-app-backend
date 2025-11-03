<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class isFinance
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $role = $request->user()->role;
        if ($role == 'Finance' or $role =='Director') {
            return $next($request);
        }else{
            return response()->json(['isNotAthorized' => true], 400);
        }
    }
}
