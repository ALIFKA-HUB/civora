<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePendingAdminActivation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->role === 'admin', 403, 'Akses ditolak.');
        abort_if($user->account_status === 'inactive', 401, 'Sesi tidak sah.');
        abort_unless($user->account_status === 'pending_activation' || $user->must_change_password, 403, 'Aktivasi akun tidak diperlukan.');
        abort_unless($user->temporaryCredentials()->whereNull('consumed_at')->where('expires_at', '>', now())->exists(), 401, 'Sesi tidak sah.');

        return $next($request);
    }
}
