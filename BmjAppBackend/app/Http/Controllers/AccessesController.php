<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Accesses;
use Symfony\Component\HttpFoundation\Response;

class AccessesController extends Controller
{
    public function index()
    {
        try {
            $accesses = Accesses::all();
            return response()->json([
                'message' => 'Accesses retrieved successfully',
                'data' => $accesses
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $access = Accesses::find($id);

            if (!$access) {
                return $this->handleNotFound('Access not found');
            }

            return response()->json([
                'message' => 'Access retrieved successfully',
                'data' => $access
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $access = Accesses::create($request->all());
            return response()->json([
                'message' => 'Access created successfully',
                'data' => $access
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Access creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $access = Accesses::find($id);

            if (!$access) {
                return $this->handleNotFound('Access not found');
            }

            $access->update($request->all());
            return response()->json([
                'message' => 'Access updated successfully',
                'data' => $access
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Access update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $access = Accesses::find($id);

            if (!$access) {
                return $this->handleNotFound('Access not found');
            }

            $access->delete();
            return response()->json([
                'message' => 'Access deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Access deletion failed');
        }
    }

    // Helper methods for consistent error handling
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
        return response()->json([
            'message' => $message,
            'error' => $th->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_NOT_FOUND);
    }
}
