<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Added for error logging
use App\Models\Quotation;
use App\Models\PurchaseOrder;
use App\Models\ProformaInvoice;
use App\Models\DeliveryOrder;
use App\Models\WorkOrder;
use App\Models\BranchSparepart;
use App\Models\BackOrder;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\DetailQuotation;
use App\Models\Branch;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get summary data for the director's dashboard.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSummary(Request $request)
    {
        try {
            // --- Time Interval Selection ---
            $interval = strtolower($request->query('interval', '30d'));
            $branchParam = $request->query('branch');

            $now = now();
            $startDate = $now->copy();
            $endDate = $now->copy()->endOfDay();

            switch ($interval) {
                case '7d':
                    $startDate = $now->copy()->subDays(6)->startOfDay();
                    break;
                case 'quarter':
                    $startDate = $now->copy()->startOfQuarter()->startOfDay();
                    break;
                case '6m':
                    $startDate = $now->copy()->subMonths(5)->startOfMonth()->startOfDay();
                    break;
                case '12m':
                    $startDate = $now->copy()->subMonths(11)->startOfMonth()->startOfDay();
                    break;
                case '30d':
                default:
                    $startDate = $now->copy()->subDays(29)->startOfDay();
                    break;
            }

            [$branch, $branchError] = $this->resolveBranchSelection($branchParam);
            if ($branchError) {
                return response()->json([
                    'status' => 'error',
                    'message' => $branchError,
                ], 400);
            }
            $branchId = $branch?->id;

            $rangeDays = max($startDate->diffInDays($endDate) + 1, 1);
            $comparisonEnd = $startDate->copy()->subDay()->endOfDay();
            $comparisonStart = $comparisonEnd->copy()->subDays($rangeDays - 1)->startOfDay();

            // --- DYNAMIC KPIs (Based on selected interval) ---
            $revenueInInterval = Quotation::where('current_status', QuotationController::FULL_PAID)
                ->whereNotIn('current_status', [QuotationController::REJECTED, QuotationController::CANCELLED])
                ->whereBetween('date', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->sum('grand_total');

            $potentialRevenue = Quotation::whereNotIn('current_status', [QuotationController::PO, QuotationController::REJECTED, QuotationController::CANCELLED])
                ->whereBetween('date', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->sum('grand_total');

            $quotesInInterval = Quotation::whereBetween('created_at', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $posFromQuotesInInterval = PurchaseOrder::whereHas('quotation', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate]);
            })->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('quotation', fn ($relation) => $relation->where('branch_id', $branchId));
            })->count();
            $quoteToPoConversionRate = $quotesInInterval > 0 ? ($posFromQuotesInInterval / $quotesInInterval) * 100 : 0;
            $quotesPrev = Quotation::whereBetween('created_at', [$comparisonStart, $comparisonEnd])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $posFromQuotesPrev = PurchaseOrder::whereHas('quotation', function ($q) use ($comparisonStart, $comparisonEnd) {
                $q->whereBetween('created_at', [$comparisonStart, $comparisonEnd]);
            })->when($branchId, function ($query) use ($branchId) {
                $query->whereHas('quotation', fn ($relation) => $relation->where('branch_id', $branchId));
            })->count();
            $quoteToPoConversionRatePrev = $quotesPrev > 0 ? ($posFromQuotesPrev / $quotesPrev) * 100 : 0;

            $paidSparepartQuotes = ProformaInvoice::where('is_full_paid', true)
                // Use the invoice date to attribute revenue to the selected interval (more stable than updated_at)
                ->whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->where('quotations.type', 'Spareparts')
                ->when($branchId, fn ($query) => $query->where('quotations.branch_id', $branchId))
                ->select('quotations.id')
                ->pluck('quotations.id');

            $totalRevenueSpareparts = DetailQuotation::whereIn('quotation_id', $paidSparepartQuotes)->sum(DB::raw('quantity * unit_price'));

            // FIX: Correctly calculate the cost of goods sold using the average purchase price from suppliers (`detail_spareparts`)
            // instead of the selling price. This provides an accurate Gross Profit Margin.
            $totalCostSpareparts = DetailQuotation::whereIn('quotation_id', $paidSparepartQuotes)
                ->join('spareparts', 'detail_quotations.sparepart_id', '=', 'spareparts.id')
                // Join with a subquery that calculates the average buying price for each sparepart.
                ->leftJoin(DB::raw('(SELECT sparepart_id, AVG(unit_price) as avg_cost FROM detail_spareparts GROUP BY sparepart_id) as costs'), function ($join) {
                    $join->on('spareparts.id', '=', 'costs.sparepart_id');
                })
                // Sum the cost (quantity * average cost) for all items in the paid quotations.
                // COALESCE ensures that if a sparepart has no buy price, its cost is treated as 0.
                ->sum(DB::raw('detail_quotations.quantity * COALESCE(costs.avg_cost, 0)'));

            $grossProfitMargin = $totalRevenueSpareparts > 0 ? (($totalRevenueSpareparts - $totalCostSpareparts) / $totalRevenueSpareparts) * 100 : 0;

            $paidSparepartQuotesPrev = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$comparisonStart, $comparisonEnd])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->where('quotations.type', 'Spareparts')
                ->when($branchId, fn ($query) => $query->where('quotations.branch_id', $branchId))
                ->select('quotations.id')
                ->pluck('quotations.id');

            $totalRevenueSparepartsPrev = DetailQuotation::whereIn('quotation_id', $paidSparepartQuotesPrev)->sum(DB::raw('quantity * unit_price'));
            $totalCostSparepartsPrev = DetailQuotation::whereIn('quotation_id', $paidSparepartQuotesPrev)
                ->join('spareparts', 'detail_quotations.sparepart_id', '=', 'spareparts.id')
                ->leftJoin(DB::raw('(SELECT sparepart_id, AVG(unit_price) as avg_cost FROM detail_spareparts GROUP BY sparepart_id) as costs'), function ($join) {
                    $join->on('spareparts.id', '=', 'costs.sparepart_id');
                })
                ->sum(DB::raw('detail_quotations.quantity * COALESCE(costs.avg_cost, 0)'));

            $grossProfitMarginPrev = $totalRevenueSparepartsPrev > 0 ? (($totalRevenueSparepartsPrev - $totalCostSparepartsPrev) / $totalRevenueSparepartsPrev) * 100 : 0;

            $paidRevenueCurrent = (float) ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->sum('grand_total');
            $paidRevenuePrevious = (float) ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$comparisonStart, $comparisonEnd])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->sum('grand_total');
            $totalInvoicedCurrent = (float) ProformaInvoice::whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->sum('grand_total');
            $totalInvoicedPrevious = (float) ProformaInvoice::whereBetween('proforma_invoice_date', [$comparisonStart, $comparisonEnd])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->sum('grand_total');
            $collectionRate = $totalInvoicedCurrent > 0 ? ($paidRevenueCurrent / $totalInvoicedCurrent) * 100 : 0;
            $collectionRatePrev = $totalInvoicedPrevious > 0 ? ($paidRevenuePrevious / $totalInvoicedPrevious) * 100 : 0;

            // --- STATIC KPIs & DATA (Point-in-time or fixed interval) ---
            $openWorkOrders = WorkOrder::where('is_done', false)
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();
            $pendingQuotations = Quotation::where('review', false)->where('current_status', 'On Review')
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $pendingReturns = Quotation::where('is_return', true)
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->count();
            $pendingPurchaseOrders = PurchaseOrder::where('current_status', 'Prepare')
                ->when($branchId, fn ($query) => $query->whereHas('quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();
            $pendingBackOrders = BackOrder::where('current_status', 'Process')
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();
            $lowStockSpareparts = $branchId
                ? BranchSparepart::where('branch_id', $branchId)->where('quantity', '<', 10)->count()
                : BranchSparepart::select('sparepart_id', DB::raw('SUM(quantity) as total_quantity'))
                    ->groupBy('sparepart_id')
                    ->havingRaw('SUM(quantity) < ?', [10])
                    ->count();
            $pendingWorkOrders = WorkOrder::where('current_status', 'On Progress')
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();

            $inventoryAlerts = BranchSparepart::with(['sparepart', 'branch'])
                ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->orderBy('quantity')
                ->limit(5)
                ->get()
                ->map(function ($row) {
                    return [
                        'sparepart' => $row->sparepart?->sparepart_name ?? '-',
                        'branch' => $row->branch?->name ?? '-',
                        'quantity' => (int) $row->quantity,
                    ];
                })
                ->values()
                ->toArray();

            // --- DYNAMIC CHARTS ---
            $paidInvoicesCount = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();
            $paidInvoicesPrev = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$comparisonStart, $comparisonEnd])
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->count();

            $salesFunnel = [
                ['stage' => 'Quotation', 'count' => $quotesInInterval],
                ['stage' => 'Purchase Order', 'count' => $posFromQuotesInInterval],
                ['stage' => 'Paid Invoice', 'count' => $paidInvoicesCount],
            ];

            $revenueByType = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->when($branchId, fn ($query) => $query->where('quotations.branch_id', $branchId))
                ->select('quotations.type', DB::raw('SUM(proforma_invoices.grand_total) as total_revenue'))
                ->groupBy('quotations.type')
                ->get();

            $salesTeamPerformance = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoice_date', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->join('employees', 'quotations.employee_id', '=', 'employees.id')
                ->when($branchId, fn ($query) => $query->where('quotations.branch_id', $branchId))
                ->select('employees.fullname', DB::raw('SUM(proforma_invoices.grand_total) as total_revenue'))
                // FIX: Group by ID to prevent issues with duplicate names.
                ->groupBy('employees.id', 'employees.fullname')
                ->orderByDesc('total_revenue')
                ->limit(7)
                ->get();

            // --- STATIC CHARTS ---
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
            // FIX: Changed revenue trend query to use `proforma_invoice_date` instead of `updated_at`.
            // This provides a more consistent time-series view (accrual basis), showing revenue in the month it was invoiced.
            $revenueData = ProformaInvoice::select(
                DB::raw('YEAR(proforma_invoice_date) as year'),
                DB::raw('MONTHNAME(proforma_invoice_date) as month'),
                DB::raw('SUM(CASE WHEN is_full_paid = 1 THEN grand_total ELSE 0 END) as paid'),
                DB::raw('SUM(CASE WHEN is_full_paid = 0 THEN grand_total ELSE 0 END) as unpaid')
            )->where('proforma_invoice_date', '>=', $sixMonthsAgo)
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->groupBy('year', DB::raw('MONTH(proforma_invoice_date)'), 'month')
                ->orderBy('year', 'asc')->orderBy(DB::raw('MONTH(proforma_invoice_date)'), 'asc')->get();

            $revenueTrend = [];
            for ($i = 5; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $monthName = $date->format('F');
                $found = $revenueData->firstWhere('month', $monthName);
                $revenueTrend[] = ['month' => $monthName, 'paid' => (float)($found->paid ?? 0), 'unpaid' => (float)($found->unpaid ?? 0)];
            }

            // FIX: Replaced 4 separate queries with a single, more efficient query for AR Aging.
            $arAgingResult = ProformaInvoice::where('is_full_paid', false)
                ->when($branchId, fn ($query) => $query->whereHas('purchaseOrder.quotation', fn ($relation) => $relation->where('branch_id', $branchId)))
                ->select(
                    DB::raw('SUM(CASE WHEN proforma_invoice_date >= "' . now()->subDays(30)->toDateString() . '" THEN grand_total ELSE 0 END) as current_total'),
                    DB::raw('SUM(CASE WHEN proforma_invoice_date BETWEEN "' . now()->subDays(60)->toDateString() . '" AND "' . now()->subDays(31)->toDateString() . '" THEN grand_total ELSE 0 END) as "31_60_days"'),
                    DB::raw('SUM(CASE WHEN proforma_invoice_date BETWEEN "' . now()->subDays(90)->toDateString() . '" AND "' . now()->subDays(61)->toDateString() . '" THEN grand_total ELSE 0 END) as "61_90_days"'),
                    DB::raw('SUM(CASE WHEN proforma_invoice_date < "' . now()->subDays(90)->toDateString() . '" THEN grand_total ELSE 0 END) as over_90_days')
                )
                ->first();

            $arAging = [
                'current' => (float)($arAgingResult->current_total ?? 0),
                '31_60_days' => (float)($arAgingResult->{'31_60_days'} ?? 0),
                '61_90_days' => (float)($arAgingResult->{'61_90_days'} ?? 0),
                'over_90_days' => (float)($arAgingResult->over_90_days ?? 0),
            ];


            $topCustomers = ProformaInvoice::where('is_full_paid', true)
                // FIX: Specify the table name for 'updated_at' to resolve ambiguity.
                ->where('proforma_invoices.updated_at', '>=', now()->subDays(90))
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->when($branchId, fn ($query) => $query->where('quotations.branch_id', $branchId))
                ->join('customers', 'quotations.customer_id', '=', 'customers.id')
                ->select('customers.company_name', DB::raw('SUM(proforma_invoices.grand_total) as total_revenue'))
                // FIX: Group by ID to prevent issues with duplicate company names.
                ->groupBy('customers.id', 'customers.company_name')->orderByDesc('total_revenue')->limit(5)->get();

            $branchMix = Branch::select('branches.id', 'branches.name', 'branches.code', DB::raw('COALESCE(SUM(quotations.grand_total), 0) as revenue'))
                ->leftJoin('quotations', function ($join) use ($startDate, $endDate) {
                    $join->on('quotations.branch_id', '=', 'branches.id')
                        ->whereBetween('quotations.date', [$startDate->toDateString(), $endDate->toDateString()]);
                })
                ->groupBy('branches.id', 'branches.name', 'branches.code')
                ->orderBy('branches.name')
                ->get();

            $revenueByType = $revenueByType->map(function ($row) {
                return [
                    'type' => $row->type,
                    'revenue' => (float) $row->total_revenue,
                ];
            })->values()->toArray();

            $salesTeamPerformance = $salesTeamPerformance->map(function ($row) {
                return [
                    'name' => $row->fullname,
                    'revenue' => (float) $row->total_revenue,
                ];
            })->values()->toArray();

            $topCustomers = $topCustomers->map(function ($row) {
                return [
                    'customer' => $row->company_name,
                    'revenue' => (float) $row->total_revenue,
                ];
            })->values()->toArray();

            $branchMix = $branchMix->map(function ($row) use ($branchId) {
                return [
                    'name' => $row->name,
                    'code' => $row->code,
                    'revenue' => (float) $row->revenue,
                    'is_selected' => $branchId ? $row->id === $branchId : false,
                ];
            })->values()->toArray();

            $availableBranches = Branch::orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])
                ->values()
                ->toArray();

            $selectedBranch = $branch
                ? ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code]
                : ['name' => 'All Branches', 'code' => 'ALL'];

            $headline = [
                [
                    'label' => 'Actual Revenue',
                    'value' => round($paidRevenueCurrent, 2),
                    'unit' => 'IDR',
                    'change_percent' => $this->percentChange($paidRevenueCurrent, $paidRevenuePrevious),
                ],
                [
                    'label' => 'Potential Pipeline',
                    'value' => round($potentialRevenue, 2),
                    'unit' => 'IDR',
                    'change_percent' => null,
                ],
                [
                    'label' => 'Gross Profit Margin',
                    'value' => round($grossProfitMargin, 2),
                    'unit' => '%',
                    'change_percent' => $this->percentChange($grossProfitMargin, $grossProfitMarginPrev),
                ],
                [
                    'label' => 'Quote to PO Conversion',
                    'value' => round($quoteToPoConversionRate, 2),
                    'unit' => '%',
                    'change_percent' => $this->percentChange($quoteToPoConversionRate, $quoteToPoConversionRatePrev),
                ],
                [
                    'label' => 'Cash Collection Rate',
                    'value' => round($collectionRate, 2),
                    'unit' => '%',
                    'change_percent' => $this->percentChange($collectionRate, $collectionRatePrev),
                ],
            ];

            $sales = [
                'funnel' => $salesFunnel,
                'team_performance' => $salesTeamPerformance,
                'pipeline' => [
                    'potential_value' => round($potentialRevenue, 2),
                    'quote_to_po_conversion' => round($quoteToPoConversionRate, 2),
                    'quote_to_po_change' => $this->percentChange($quoteToPoConversionRate, $quoteToPoConversionRatePrev),
                    'paid_invoices' => [
                        'current' => $paidInvoicesCount,
                        'previous' => $paidInvoicesPrev,
                    ],
                ],
            ];

            $finance = [
                'actual_revenue' => [
                    'current' => round($paidRevenueCurrent, 2),
                    'previous' => round($paidRevenuePrevious, 2),
                    'change_percent' => $this->percentChange($paidRevenueCurrent, $paidRevenuePrevious),
                ],
                'gross_margin' => [
                    'current' => round($grossProfitMargin, 2),
                    'previous' => round($grossProfitMarginPrev, 2),
                    'change_percent' => $this->percentChange($grossProfitMargin, $grossProfitMarginPrev),
                ],
                'collection_rate' => [
                    'current' => round($collectionRate, 2),
                    'previous' => round($collectionRatePrev, 2),
                    'change_percent' => $this->percentChange($collectionRate, $collectionRatePrev),
                ],
                'revenue_by_type' => $revenueByType,
                'revenue_trend' => $revenueTrend,
                'branch_mix' => $branchMix,
                'receivables_aging' => $arAging,
            ];

            $operations = [
                'pending_quotations' => $pendingQuotations,
                'pending_returns' => $pendingReturns,
                'open_work_orders' => $openWorkOrders,
                'pending_work_orders' => $pendingWorkOrders,
                'pending_purchase_orders' => $pendingPurchaseOrders,
                'pending_back_orders' => $pendingBackOrders,
                'low_stock_spareparts' => $lowStockSpareparts,
                'inventory_alerts' => $inventoryAlerts,
            ];

            $customers = [
                'top_customers' => $topCustomers,
            ];

            $filters = [
                'interval' => $interval,
                'date_range' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                    'comparison_start' => $comparisonStart->toDateString(),
                    'comparison_end' => $comparisonEnd->toDateString(),
                ],
                'branch' => $selectedBranch,
                'available_branches' => $availableBranches,
            ];

            $payload = [
                'filters' => $filters,
                'headline' => $headline,
                'sales' => $sales,
                'finance' => $finance,
                'operations' => $operations,
                'customers' => $customers,
            ];

            return response()->json(['status' => 'success', 'data' => $payload], 200);
        } catch (\Exception $e) {
            // Log the exception for debugging purposes
            Log::error('Failed to get dashboard summary: ' . $e->getMessage());

            // Return a generic error response to the client
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while fetching dashboard data.'
            ], 500);
        }
    }

    protected function resolveBranchSelection(?string $branchParam): array
    {
        if (!$branchParam || strtolower($branchParam) === 'all') {
            return [null, null];
        }

        $normalized = strtolower($branchParam);
        $branch = Branch::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw('LOWER(code) = ?', [$normalized])
            ->first();

        if (!$branch) {
            return [null, 'Branch not found. Valid options are Jakarta, Semarang, or leave empty for all branches.'];
        }

        return [$branch, null];
    }

    protected function percentChange($current, $previous): ?float
    {
        if ($previous === null) {
            return null;
        }

        $previous = (float) $previous;

        if (abs($previous) < 0.00001) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
