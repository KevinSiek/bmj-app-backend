<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class ValidationController extends Controller
{
    public function unique(Request $request): JsonResponse
    {
        $table = $request->get('table');
        $field = $request->get('field');
        $value = $request->get('value');

        if (!$table || !$field || $value === null) {
            return response()->json(['unique' => false, 'error' => 'Missing parameters'], 422);
        }

        $exists = DB::table($table)->where($field, $value)->exists();

        return response()->json(['unique' => !$exists]);
    }
}
