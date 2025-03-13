<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BackOrder;
use Symfony\Component\HttpFoundation\Response;

class BackOrderController extends Controller
{
    public function index()
    {
        try {
            $backOrders = BackOrder::with('purchaseOrder')->get();
            return response()->json([
                'message' => 'Back orders retrieved successfully',
                'data' => $backOrders
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $backOrder = BackOrder::with('purchaseOrder')->find($id);

            if (!$backOrder) {
                return $this->handleNotFound('Back order not found');
            }

            return response()->json([
                'message' => 'Back order retrieved successfully',
                'data' => $backOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $backOrder = BackOrder::create($request->all());
            return response()->json([
                'message' => 'Back order created successfully',
                'data' => $backOrder
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Back order creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $backOrder = BackOrder::find($id);

            if (!$backOrder) {
                return $this->handleNotFound('Back order not found');
            }

            $backOrder->update($request->all());
            return response()->json([
                'message' => 'Back order updated successfully',
                'data' => $backOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Back order update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $backOrder = BackOrder::find($id);

            if (!$backOrder) {
                return $this->handleNotFound('Back order not found');
            }

            $backOrder->delete();
            return response()->json([
                'message' => 'Back order deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Back order deletion failed');
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
