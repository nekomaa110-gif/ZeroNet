<?php

namespace App\Http\Middleware;

use App\Models\CustomerContact;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerPhone
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('customer')->user();
        if (! $user) {
            return $next($request);
        }

        $contact = CustomerContact::where('username', $user->getAuthIdentifier())->first();

        if (! $contact || empty($contact->phone)) {
            return redirect()->route('customer.phone.show');
        }

        return $next($request);
    }
}
