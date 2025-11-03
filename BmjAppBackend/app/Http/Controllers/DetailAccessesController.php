<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DetailAccesses;

class DetailAccessesController extends Controller
{
    public function index() {
        return DetailAccesses::with('employee', 'accesses')->get();
    }

    public function show($id) {
        return DetailAccesses::with('employee', 'accesses')->find($id);
    }

    public function store(Request $request) {
        return DetailAccesses::create($request->all());
    }

    public function destroy($id) {
        return DetailAccesses::destroy($id);
    }
}
