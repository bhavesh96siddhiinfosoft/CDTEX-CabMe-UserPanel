<?php

namespace App\Http\Middleware;

use App\Support\Rider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards the signed-in area. No rider in session means no access token to call the API with. */
class EnsureRider
{
    public function __construct(private readonly Rider $rider)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->rider->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Remember where they were headed so login can bounce them back.
        return redirect()->route('login')->with('intended', $request->fullUrl());
    }
}
