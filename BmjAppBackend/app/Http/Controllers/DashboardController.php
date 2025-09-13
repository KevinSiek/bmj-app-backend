<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Added for error logging
use App\Models\Quotation;
use App\Models\PurchaseOrder;
use App\Models\ProformaInvoice;
use App\Models\Invoice;
use App\Models\DeliveryOrder;
use App\Models\WorkOrder;
use App\Models\Sparepart;
use App\Models\BackOrder;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\DetailQuotation;
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
            $interval = $request->query('interval', '30d');
            $startDate = now();
            $endDate = now();

            switch ($interval) {
                case '7d':
                    $startDate = now()->subDays(6)->startOfDay();
                    break;
                case 'quarter':
                    $startDate = now()->startOfQuarter();
                    break;
                case '6m':
                    $startDate = now()->subMonths(5)->startOfMonth();
                    break;
                case '12m':
                    $startDate = now()->subMonths(11)->startOfMonth();
                    break;
                case '30d':
                default:
                    $startDate = now()->subDays(29)->startOfDay();
                    break;
            }

            // --- DYNAMIC KPIs (Based on selected interval) ---
            $revenueInInterval = Quotation::where('current_status', QuotationController::FULL_PAID)
                ->whereNotIn('current_status', [QuotationController::REJECTED, QuotationController::CANCELLED])
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('grand_total');

            $potentialRevenue = Quotation::whereNotIn('current_status', [QuotationController::PO, QuotationController::REJECTED, QuotationController::CANCELLED])
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('grand_total');

            $quotesInInterval = Quotation::whereBetween('created_at', [$startDate, $endDate])->count();
            $posFromQuotesInInterval = PurchaseOrder::whereHas('quotation', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('created_at', [$startDate, $endDate]);
            })->count();
            $quoteToPoConversionRate = $quotesInInterval > 0 ? ($posFromQuotesInInterval / $quotesInInterval) * 100 : 0;

            $paidSparepartQuotes = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoices.updated_at', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->where('quotations.type', 'Spareparts')
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

            // --- STATIC KPIs & DATA (Point-in-time or fixed interval) ---
            $openWorkOrders = WorkOrder::where('is_done', false)->count();
            $pendingQuotations = Quotation::where('review', false)->where('current_status', 'On Review')->count();
            $pendingReturns = Quotation::where('is_return', true)->count();
            $pendingPurchaseOrders = PurchaseOrder::where('current_status', 'Prepare')->count();
            $pendingBackOrders = BackOrder::where('current_status', 'Process')->count();
            $lowStockSpareparts = Sparepart::where('total_unit', '<', 10)->count();
            $pendingWorkOrders = WorkOrder::where('current_status', 'On Progress')->count();

            // --- DYNAMIC CHARTS ---
            $salesFunnel = [
                'quotations' => $quotesInInterval,
                'purchase_orders' => $posFromQuotesInInterval,
                'paid_invoices' => ProformaInvoice::where('is_full_paid', true)->whereBetween('updated_at', [$startDate, $endDate])->count(),
            ];

            $revenueByType = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoices.updated_at', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->select('quotations.type', DB::raw('SUM(proforma_invoices.grand_total) as total_revenue'))
                ->groupBy('quotations.type')
                ->get();

            $salesTeamPerformance = ProformaInvoice::where('is_full_paid', true)
                ->whereBetween('proforma_invoices.updated_at', [$startDate, $endDate])
                ->join('purchase_orders', 'proforma_invoices.purchase_order_id', '=', 'purchase_orders.id')
                ->join('quotations', 'purchase_orders.quotation_id', '=', 'quotations.id')
                ->join('employees', 'quotations.employee_id', '=', 'employees.id')
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
                ->join('customers', 'quotations.customer_id', '=', 'customers.id')
                ->select('customers.company_name', DB::raw('SUM(proforma_invoices.grand_total) as total_revenue'))
                // FIX: Group by ID to prevent issues with duplicate company names.
                ->groupBy('customers.id', 'customers.company_name')->orderByDesc('total_revenue')->limit(5)->get();

            $summary = [
                // KPIs
                'revenue_in_interval' => $revenueInInterval,
                'potential_revenue' => $potentialRevenue,
                'open_work_orders' => $openWorkOrders,
                'quote_to_po_conversion_rate' => $quoteToPoConversionRate,
                'gross_profit_margin' => $grossProfitMargin,

                // DYNAMIC CHARTS
                'sales_funnel' => $salesFunnel,
                'revenue_by_type' => $revenueByType,
                'sales_team_performance' => $salesTeamPerformance,

                // STATIC CHARTS & DATA
                'revenue_trend' => ['series' => $revenueTrend, 'target' => 350000000],
                'ar_aging' => $arAging,
                'operational_bottlenecks' => [
                    'pending_quotations' => $pendingQuotations,
                    'pending_returns' => $pendingReturns,
                    'pending_purchase_orders' => $pendingPurchaseOrders,
                    'pending_work_orders' => $pendingWorkOrders,
                    'pending_back_orders' => $pendingBackOrders,
                    'low_stock_spareparts' => $lowStockSpareparts,
                ],
                'top_customers_by_revenue' => $topCustomers,
            ];

            return response()->json(['status' => 'success', 'data' => $summary], 200);
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
}
