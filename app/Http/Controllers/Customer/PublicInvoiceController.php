<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerContact;
use App\Models\Invoice;

class PublicInvoiceController extends Controller
{
    public function show(string $slug)
    {
        $invoice = Invoice::where('slug', $slug)->firstOrFail();
        $contact = CustomerContact::where('username', $invoice->username)->first();

        return view('customer.public-invoice', [
            'invoice' => $invoice,
            'contact' => $contact,
            'banks' => (array) config('services.billing.rekening', []),
            'businessWa' => (string) config('services.billing.business_wa'),
        ]);
    }
}
