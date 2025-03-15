<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use Symfony\Component\HttpFoundation\Response;

class SparepartController extends Controller
{
    public function index()
    {
        try {
            $spareparts = Sparepart::all();
            return response()->json([
                'message' => 'Spareparts retrieved successfully',
                'data' => $spareparts
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $spareparts = Sparepart::find($id);

            if (!$spareparts) {
                return $this->handleNotFound('Spareparts not found');
            }

            return response()->json([
                'message' => 'Spareparts retrieved successfully',
                'data' => $spareparts
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $spareparts = Sparepart::create($request->all());
            return response()->json([
                'message' => 'Spareparts created successfully',
                'data' => $spareparts
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Spareparts creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $spareparts = Sparepart::find($id);

            if (!$spareparts) {
                return $this->handleNotFound('Spareparts not found');
            }

            $spareparts->update($request->all());
            return response()->json([
                'message' => 'Spareparts updated successfully',
                'data' => $spareparts
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Spareparts update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $spareparts = Sparepart::find($id);

            if (!$spareparts) {
                return $this->handleNotFound('Spareparts not found');
            }

            $spareparts->delete();
            return response()->json([
                'message' => 'Spareparts deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Spareparts deletion failed');
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
