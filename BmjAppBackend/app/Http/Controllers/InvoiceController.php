<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index()
    {
        try {
            $invoices = Invoice::with('proformaInvoice', 'employee')->get();
            return response()->json([
                'message' => 'Invoices retrieved successfully',
                'data' => $invoices
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function show($id)
    {
        try {
            $invoice = Invoice::with('proformaInvoice', 'employee')->find($id);

            if (!$invoice) {
                return $this->handleNotFound('Invoice not found');
            }

            return response()->json([
                'message' => 'Invoice retrieved successfully',
                'data' => $invoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function store(Request $request)
    {
        try {
            $invoice = Invoice::create($request->all());
            return response()->json([
                'message' => 'Invoice created successfully',
                'data' => $invoice
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Invoice creation failed');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return $this->handleNotFound('Invoice not found');
            }

            $invoice->update($request->all());
            return response()->json([
                'message' => 'Invoice updated successfully',
                'data' => $invoice
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Invoice update failed');
        }
    }

    public function destroy($id)
    {
        try {
            $invoice = Invoice::find($id);

            if (!$invoice) {
                return $this->handleNotFound('Invoice not found');
            }

            $invoice->delete();
            return response()->json([
                'message' => 'Invoice deleted successfully',
                'data' => null
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Invoice deletion failed');
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
