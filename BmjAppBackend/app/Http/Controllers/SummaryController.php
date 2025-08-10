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

            $quotationCount = collect();
            $poCount = collect();
            $woCount = collect();
            $doCount = collect();

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

            $quotationCount = collect();
            $quotationApproveCount = collect();
            $quotationReviewCount = collect();
            $quotationRejectCount = collect();
            $poCount = collect();
            $woCount = collect();
            $doCount = collect();

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
                $poDoneCount = PurchaseOrder::where('employee_id', $userId)
                    ->whereYear('purchase_order_date', $currentYear)
                    ->whereMonth('purchase_order_date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::DONE)
                    ->count();
                $woCount = WorkOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->count();
                $woDoneCount = WorkOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('start_date', $currentYear)
                    ->whereMonth('start_date', $currentMonth)
                    ->where('current_status', WorkOrderController::DONE)
                    ->count();
                $doCount = DeliveryOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('delivery_order_date', $currentYear)
                    ->whereMonth('delivery_order_date', $currentMonth)
                    ->count();
                $doDoneCount = DeliveryOrder::whereHas('purchaseOrder', function($query) use ($userId) {
                        $query->where('employee_id', $userId);
                    })
                    ->whereYear('delivery_order_date', $currentYear)
                    ->whereMonth('delivery_order_date', $currentMonth)
                    ->where('current_status', DeliveryOrderController::DONE)
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
                    'purchase_order' => [
                        'total' => $poCount,
                        'done' => $poDoneCount
                    ],
                    'work_order' => [
                        'total' => $woCount,
                        'done' => $woDoneCount
                    ],
                    'delivery_order' => [
                        'total' => $doCount,
                        'done' => $doDoneCount
                    ]
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

            $purchaseOrder = collect();
            $purchaseOrderPrepare = collect();
            $purchaseOrderReady = collect();
            $purchaseOrderRelease = collect();

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::INVENTORY) {
                $purchaseOrder = PurchaseOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $purchaseOrderPrepare = PurchaseOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::PREPARE)
                    ->count();
                $purchaseOrderReady = PurchaseOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', PurchaseOrderController::READY)
                    ->count();
                $purchaseOrderRelease = PurchaseOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
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

            $purchaseOrderWaitForPayment = collect();
            $purchaseOrderDpPaid = collect();
            $purchaseOrderFullPaid = collect();

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::FINANCE) {
                $purchaseOrderWaitForPayment = PurchaseOrder::whereHas('proformaInvoice', function($query) use ($userId) {
                        $query->where('is_dp_paid', false)
                            ->orWhere('is_full_paid', false);
                    })
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $purchaseOrderDpPaid = PurchaseOrder::whereHas('proformaInvoice', function($query) use ($userId) {
                        $query->where('is_dp_paid', true)
                            ->orWhere('is_full_paid', false);
                    })
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $purchaseOrderFullPaid = PurchaseOrder::whereHas('proformaInvoice', function($query) use ($userId) {
                        $query->where('is_dp_paid', true)
                            ->orWhere('is_full_paid', true);
                    })
                    ->whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
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

            $workOrder = collect();
            $workOrderOnProgress = collect();
            $workOrderDone = collect();

            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;
            if($role === EmployeeController::SERVICE) {
                $workOrder = WorkOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->count();
                $workOrderOnProgress = WorkOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
                    ->where('current_status', WorkOrderController::ON_PROGRESS)
                    ->count();
                $workOrderDone = WorkOrder::whereYear('date', $currentYear)
                    ->whereMonth('date', $currentMonth)
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
        return response()->json([
            'message' => $message,
            'error' => $th->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
