<?php

namespace App\Http\Middleware;

use App\Actions\Accounts\AccountSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->role === 'admin', 403, 'Akses ditolak.');

        if (! AccountSecurity::active($user)) {
            if ($user->account_status === 'pending_activation' || $user->must_change_password) {
                return redirect()->route('admin.activate');
            }

            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
