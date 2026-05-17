<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        /** @var User|null $user */
        $user = Auth::guard('sanctum')->user();
        
        if (!$user || !$user->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // IP 白名单检查
        $whitelist = admin_setting('admin_ip_whitelist', '');
        if (!empty($whitelist)) {
            $allowedIps = array_map('trim', explode(',', $whitelist));
            $clientIp = $request->ip();
            if (!in_array($clientIp, $allowedIps)) {
                return response()->json(['message' => 'Access denied: IP not in whitelist'], 403);
            }
        }
        
        return $next($request);
    }
}
