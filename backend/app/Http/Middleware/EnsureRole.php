<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role guard. Authorization is enforced in the API, not only in the
 * UI, so a student can never reach another student's data even by crafting a
 * request directly.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'يجب تسجيل الدخول.'], 401);
        }

        $allowed = $roles;
        foreach ($allowed as $role) {
            if ($role === 'owner' && $user->isOwner()) {
                return $next($request);
            }
            if ($user->role === $role) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'لا تملك صلاحية الوصول لهذا المورد.'], 403);
    }
}
