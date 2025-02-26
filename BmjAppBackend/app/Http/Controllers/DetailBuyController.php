<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DetailBuy;

class DetailBuyController extends Controller
{
    public function index() {
        return DetailBuy::with('buy', 'goods')->get();
    }

    public function show($id) {
        return DetailBuy::with('buy', 'goods')->find($id);
    }

    public function store(Request $request) {
        return DetailBuy::create($request->all());
    }

    public function update(Request $request, $id) {
        $detailBuy = DetailBuy::find($id);
        $detailBuy->update($request->all());
        return $detailBuy;
    }

    public function destroy($id) {
        return DetailBuy::destroy($id);
    }
}
