<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JustinHoltWeb\Chisel\Chisel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Chisel CP route: signed in (the cp group), not impersonating, and an admin
 * (unless `requireAdmin` is false in the config file). Finer checks are per route.
 */
class EnsureChiselAccess
{
    public function __construct(private readonly Chisel $chisel) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->chisel->canAccess($request->craftUser()), 403);

        return $next($request);
    }
}
