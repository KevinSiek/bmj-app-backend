<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Accesses;

class AccessesController extends Controller
{
    public function index() {
        return Accesses::all();
    }

    public function show($id) {
        return Accesses::find($id);
    }

    public function store(Request $request) {
        return Accesses::create($request->all());
    }

    public function update(Request $request, $id) {
        $Accesses = Accesses::find($id);
        $Accesses->update($request->all());
        return $Accesses;
    }

    public function destroy($id) {
        return Accesses::destroy($id);
    }
}
