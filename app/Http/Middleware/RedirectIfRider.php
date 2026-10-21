<?php

namespace App\Http\Middleware;

use App\Support\Rider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Keeps signed-in riders off the login and register pages. */
class RedirectIfRider
{
    public function __construct(private readonly Rider $rider)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $this->rider->check()
            ? redirect()->route('app.home')
            : $next($request);
    }
}
