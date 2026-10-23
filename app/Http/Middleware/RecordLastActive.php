<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordLastActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->suspended_at === null) {
            $user->forceFill(['last_active_at' => now()])->save();
        }

        return $next($request);
    }
}
