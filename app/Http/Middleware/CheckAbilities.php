<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckAbilities
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthenticated',
                'error_code' => 'UNAUTHENTICATED',
                'timestamp' => now()->toIso8601String()
            ], 401);
        }

        $userAbilities = $request->input('user_abilities', []);

        // Check if user has any of the required abilities
        $hasAbility = false;
        foreach ($abilities as $ability) {
            if (in_array($ability, $userAbilities)) {
                $hasAbility = true;
                break;
            }
        }

        if (!$hasAbility) {
            Log::warning('Insufficient permissions', [
                'user_id' => $user->id,
                'required_abilities' => $abilities,
                'user_abilities' => $userAbilities,
                'ip' => $request->ip(),
                'endpoint' => $request->path()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'error_code' => 'FORBIDDEN',
                'required_abilities' => $abilities,
                'user_abilities' => $userAbilities,
                'timestamp' => now()->toIso8601String()
            ], 403);
        }

        return $next($request);
    }
}
