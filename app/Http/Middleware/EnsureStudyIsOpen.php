<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When STUDY_OPEN=false, only admin/researcher accounts may use the API -
 * every participant-facing endpoint returns 403 regardless of token
 * validity, so previously issued tokens stop working immediately and no
 * client-side change is required.
 */
class EnsureStudyIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.study_open')) {
            return $next($request);
        }

        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'The study has closed and is no longer accepting activity.',
            ], 403);
        }

        return $next($request);
    }
}
