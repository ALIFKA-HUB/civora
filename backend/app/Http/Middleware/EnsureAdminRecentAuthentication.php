<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRecentAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('admin_password_confirmed_at');

        if (! is_numeric($confirmedAt) || now()->timestamp - (int) $confirmedAt >= 15 * 60) {
            return redirect()->guest(route('admin.confirm-password'));
        }

        return $next($request);
    }
}
