<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->user() && in_array($request->user()->role, $roles)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Anda tidak punya hak akses.'], 403);
        }

        $message = 'Anda tidak punya hak akses untuk melakukan tindakan ini.';

        return $request->isMethod('GET')
            ? redirect()->route('dashboard')->with('forbidden', $message)
            : back()->with('forbidden', $message);
    }
}
