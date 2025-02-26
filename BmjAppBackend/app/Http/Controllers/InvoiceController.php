<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;

class InvoiceController extends Controller
{
    public function index() {
        return Invoice::with('proformaInvoice', 'employee')->get();
    }

    public function show($id) {
        return Invoice::with('proformaInvoice', 'employee')->find($id);
    }

    public function store(Request $request) {
        return Invoice::create($request->all());
    }

    public function update(Request $request, $id) {
        $invoice = Invoice::find($id);
        $invoice->update($request->all());
        return $invoice;
    }

    public function destroy($id) {
        return Invoice::destroy($id);
    }
}
