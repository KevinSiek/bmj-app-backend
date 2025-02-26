<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BackOrder;

class BackOrderController extends Controller
{
    public function index() {
        return BackOrder::with('purchaseOrder')->get();
    }

    public function show($id) {
        return BackOrder::with('purchaseOrder')->find($id);
    }

    public function store(Request $request) {
        return BackOrder::create($request->all());
    }

    public function update(Request $request, $id) {
        $bo = BackOrder::find($id);
        $bo->update($request->all());
        return $bo;
    }

    public function destroy($id) {
        return BackOrder::destroy($id);
    }
}
