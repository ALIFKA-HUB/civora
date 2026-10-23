<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next, string $mode = 'active', ?string $role = null): Response
    {
        $user = $request->user();
        abort_unless($user && $user->account_status !== 'inactive', 401, 'Sesi tidak sah.');
        if ($mode === 'active') {
            abort_unless($user->account_status === 'active' && ! $user->must_change_password, 403, 'Aktivasi akun diperlukan.');
        }
        abort_if($role && $user->role !== $role, 403, 'Akses ditolak.');

        return $next($request);
    }
}
