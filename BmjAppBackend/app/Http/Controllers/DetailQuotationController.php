<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DetailQuotation;

class DetailQuotationController extends Controller
{
    public function index() {
        return DetailQuotation::with('quotation', 'goods')->get();
    }

    public function show($id) {
        return DetailQuotation::with('quotation', 'goods')->find($id);
    }

    public function store(Request $request) {
        return DetailQuotation::create($request->all());
    }

    public function update(Request $request, $id) {
        $detailQuotation = DetailQuotation::find($id);
        $detailQuotation->update($request->all());
        return $detailQuotation;
    }

    public function destroy($id) {
        return DetailQuotation::destroy($id);
    }
}
