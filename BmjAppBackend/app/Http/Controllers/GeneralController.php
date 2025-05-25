<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\General;
use Symfony\Component\HttpFoundation\Response;

class GeneralController extends Controller
{
    public function getDiscount()
    {
        try {
            $latestDiscount = General::orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if (!$latestDiscount) {
                return $this->handleNotFound('No discount found');
            }

            return response()->json([
                'message' => 'Discount retrieved successfully',
                'discount' => $latestDiscount->discount
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
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

    protected function handleForbidden($message = 'Forbidden')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_FORBIDDEN);
    }
}
