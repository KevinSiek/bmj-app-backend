<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WorkOrder;
use Symfony\Component\HttpFoundation\Response;

class WorkOrderController extends Controller
{
    public function index()
    {
        try {
            $workOrders = WorkOrder::with('quotation', 'employee')->get();
            return response()->json([
                'message' => 'Work orders retrieved successfully',
                'data' => $workOrders
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $workOrder = WorkOrder::with('quotation', 'employee')->find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            return response()->json([
                'message' => 'Work order retrieved successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $workOrder = WorkOrder::create($request->all());
            return response()->json([
                'message' => 'Work order created successfully',
                'data' => $workOrder
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Work order creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $workOrder = WorkOrder::find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            $workOrder->update($request->all());
            return response()->json([
                'message' => 'Work order updated successfully',
                'data' => $workOrder
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Work order update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $workOrder = WorkOrder::find($id);

            if (!$workOrder) {
                return $this->handleNotFound('Work order not found');
            }

            $workOrder->delete();
            return response()->json([
                'message' => 'Work order deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Work order deletion failed');
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
