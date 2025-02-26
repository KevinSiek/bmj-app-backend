<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Good;

class GoodController extends Controller
{
    public function index() {
        return Good::all();
    }

    public function show($id) {
        return Good::find($id);
    }

    public function store(Request $request) {
        return Good::create($request->all());
    }

    public function update(Request $request, $id) {
        $Good = Good::find($id);
        $Good->update($request->all());
        return $Good;
    }

    public function destroy($id) {
        return Good::destroy($id);
    }
}
