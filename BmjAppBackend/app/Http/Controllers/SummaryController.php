<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SummaryController extends Controller
{
    public function summaryDirector(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $quotationCount = 0;
            $poCount = 0;
            $woCount = 0;
            $doCount = 0;

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::DIRECTOR) {
                $quotationCount = Quotation::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $poCount = PurchaseOrder::whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
                $woCount = WorkOrder::whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->count();
                $doCount = DeliveryOrder::whereYear('delivery_order_date', $currentYear)
                    ->whereMonth('delivery_order_date', $currentMonth)
                    ->count();
            }

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'quotation' => $quotationCount,
                    'purchase_order' => $poCount,
                    'work_order' => $woCount,
                    'delivery_order' => $doCount
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function summaryMarketing(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $quotationCount = 0;
            $quotationApproveCount = 0;
            $quotationReviewCount = 0;
            $quotationRejectCount = 0;
            $poCount = 0;
            $woCount = 0;
            $doCount = 0;

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::MARKETING) {
                $quotationCount = Quotation::where('employee_id', $userId)
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $quotationApproveCount = Quotation::where('employee_id', $userId)
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', QuotationController::APPROVE)
                    ->count();
                $quotationReviewCount = Quotation::where('employee_id', $userId)
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', QuotationController::ON_REVIEW)
                    ->count();
                $quotationRejectCount = Quotation::where('employee_id', $userId)
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', QuotationController::REJECTED)
                    ->count();
                $poCount = PurchaseOrder::where('employee_id', $userId)
                    ->whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
                $woCount = WorkOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->count();
                $doCount = DeliveryOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('delivery_order_date', $currentYear)
                    ->whereMonth('delivery_order_date', $currentMonth)
                    ->count();
            }

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'quotation' => [
                        'total' => $quotationCount,
                        'approve' => $quotationApproveCount,
                        'review' => $quotationReviewCount,
                        'reject' => $quotationRejectCount
                    ],
                    'purchase_order' => $poCount,
                    'work_order' => $woCount,
                    'delivery_order' => $doCount
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function summaryInventory(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $purchaseOrder = 0;
            $purchaseOrderPrepare = 0;
            $purchaseOrderReady = 0;
            $purchaseOrderRelease = 0;

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::INVENTORY_PURCHASE || $role === EmployeeController::INVENTORY_ADMIN) {
                $purchaseOrder = PurchaseOrder::whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
                $purchaseOrderPrepare = PurchaseOrder::whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::PREPARE)
                    ->count();
                $purchaseOrderReady = PurchaseOrder::whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::READY)
                    ->count();
                $purchaseOrderRelease = PurchaseOrder::whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::RELEASE)
                    ->count();
            }

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'purchase_order' => [
                        'total' => $purchaseOrder,
                        'prepare' => $purchaseOrderPrepare,
                        'ready' => $purchaseOrderReady,
                        'release' => $purchaseOrderRelease
                    ],
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function summaryFinance(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $purchaseOrderWaitForPayment = 0;
            $purchaseOrderDpPaid = 0;
            $purchaseOrderFullPaid = 0;

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::FINANCE) {
                $purchaseOrderWaitForPayment = PurchaseOrder::whereHas('proformaInvoice', function($query) {
                        $query->where('is_dp_paid', false)
                            ->where('is_full_paid', false);
                    })
                    ->whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
                $purchaseOrderDpPaid = PurchaseOrder::whereHas('proformaInvoice', function($query) {
                        $query->where('is_dp_paid', true)
                            ->where('is_full_paid', false);
                    })
                    ->whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
                $purchaseOrderFullPaid = PurchaseOrder::whereHas('proformaInvoice', function($query) {
                        $query->where('is_dp_paid', true)
                            ->where('is_full_paid', true);
                    })
                    ->whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->count();
            }

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'purchase_order' => [
                        'wait_for_payment' => $purchaseOrderWaitForPayment,
                        'dp_paid' => $purchaseOrderDpPaid,
                        'full_paid' => $purchaseOrderFullPaid
                    ]
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function summaryService(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $workOrder = 0;
            $workOrderOnProgress = 0;
            $workOrderDone = 0;

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::SERVICE) {
                $workOrder = WorkOrder::whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->count();
                // "On progress" = any active (not-yet-done) WO: Wait On Progress + On Progress.
                $workOrderOnProgress = WorkOrder::whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->whereIn('current_status', [
                        WorkOrderController::WAIT_ON_PROGRESS,
                        WorkOrderController::ON_PROGRESS,
                    ])
                    ->count();
                $workOrderDone = WorkOrder::whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->where('current_status', WorkOrderController::DONE)
                    ->count();
            }

            return response()->json([
                'message' => 'List of all quotations retrieved successfully',
                'data' => [
                    'work_order' => [
                        'total' => $workOrder,
                        'on_progress' => $workOrderOnProgress,
                        'done' => $workOrderDone
                    ]
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
        // Preserve Laravel HTTP semantics: not-found / validation / auth / http exceptions
        // must surface with their real status code, not be flattened into a generic 500 here.
        if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
            || $th instanceof \Illuminate\Validation\ValidationException
            || $th instanceof \Illuminate\Auth\Access\AuthorizationException) {
            throw $th;
        }

        return response()->json([
            'message' => $message,
            'error' => $th->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
