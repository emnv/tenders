<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $hashed = hash('sha256', $token);
        $user = User::query()->where('admin_api_token', $hashed)->first();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (!$header) {
            return null;
        }

        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
