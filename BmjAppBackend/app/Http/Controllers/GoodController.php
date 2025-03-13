<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Good;
use Symfony\Component\HttpFoundation\Response;

class GoodController extends Controller
{
    public function index()
    {
        try {
            $goods = Good::all();
            return response()->json([
                'message' => 'Goods retrieved successfully',
                'data' => $goods
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $good = Good::find($id);

            if (!$good) {
                return $this->handleNotFound('Good not found');
            }

            return response()->json([
                'message' => 'Good retrieved successfully',
                'data' => $good
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $good = Good::create($request->all());
            return response()->json([
                'message' => 'Good created successfully',
                'data' => $good
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Good creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $good = Good::find($id);

            if (!$good) {
                return $this->handleNotFound('Good not found');
            }

            $good->update($request->all());
            return response()->json([
                'message' => 'Good updated successfully',
                'data' => $good
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Good update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $good = Good::find($id);

            if (!$good) {
                return $this->handleNotFound('Good not found');
            }

            $good->delete();
            return response()->json([
                'message' => 'Good deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Good deletion failed');
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
