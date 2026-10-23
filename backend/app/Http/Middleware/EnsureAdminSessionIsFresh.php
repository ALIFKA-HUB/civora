<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionIsFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role === 'admin') {
            $lastActivity = $request->session()->get('admin_last_activity');

            if ($lastActivity && now()->timestamp - $lastActivity >= 30 * 60) {
                auth('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.login');
            }

            $request->session()->put('admin_last_activity', now()->timestamp);
        }

        return $next($request);
    }
}
