<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Data collection for the pilot study has closed. Only admin/researcher
 * accounts may continue to authenticate against the API - every
 * participant-facing endpoint now returns 403 regardless of token
 * validity, so previously issued tokens stop working the moment this
 * deploys and no client-side change is required.
 */
class EnsureStudyIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'The study has closed and is no longer accepting activity.',
            ], 403);
        }

        return $next($request);
    }
}
