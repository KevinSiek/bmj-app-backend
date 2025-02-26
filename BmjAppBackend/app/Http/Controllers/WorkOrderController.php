<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WorkOrder;

class WorkOrderController extends Controller
{
    public function index() {
        return WorkOrder::with('quotation', 'employee')->get();
    }

    public function show($id) {
        return WorkOrder::with('quotation', 'employee')->find($id);
    }

    public function store(Request $request) {
        return WorkOrder::create($request->all());
    }

    public function update(Request $request, $id) {
        $WorkOrder = WorkOrder::find($id);
        $WorkOrder->update($request->all());
        return $WorkOrder;
    }

    public function destroy($id) {
        return WorkOrder::destroy($id);
    }
}
