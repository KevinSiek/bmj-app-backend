<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    const INVOICE_DP1 = 'DP';
    const INVOICE_DP2 = 'DP2';
    const INVOICE_FINAL = 'Final';

    public function get(Request $request, $id)
    {
        try {
            $invoice = $this->getAccessedInvoice($request)
                ->with([
                    'proformaInvoice.purchaseOrder.quotation.customer',
                    'proformaInvoice.purchaseOrder.quotation.detailQuotations.sparepart',
                    'employee'
                ])
                ->findOrFail($id);

            $proformaInvoice = $invoice->proformaInvoice;
            if (!$proformaInvoice) {
                return $this->handleError(new \Exception('Proforma invoice not found for invoice #' . $invoice->invoice_number));
            }
            $purchaseOrder = $proformaInvoice->purchaseOrder;
            if (!$purchaseOrder) {
                return $this->handleError(new \Exception('Purchase order not found for invoice #' . $invoice->invoice_number));
            }
            $quotation = $purchaseOrder->quotation;
            if (!$quotation) {
                return $this->handleError(new \Exception('Quotation not found for invoice #' . $invoice->invoice_number));
            }
            $customer = $quotation->customer ?? null;

            $spareParts = [];
            $services = [];
            if ($quotation && $quotation->detailQuotations) {
                foreach ($quotation->detailQuotations as $detail) {
                    if ($detail->sparepart_id) {
                        $sparepart = $detail->sparepart;
                        $spareParts[] = [
                            'sparepart_id' => $sparepart->id ?? '',
                            'sparepart_name' => $sparepart->sparepart_name ?? '',
                            'sparepart_number' => $sparepart->sparepart_number ?? '',
                            'quantity' => $detail->quantity ?? 0,
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    } else {
                        $services[] = [
                            'service' => $detail->service ?? '',
                            'unit_price_sell' => $detail->unit_price ?? 0,
                            'quantity' => $detail->quantity ?? 0,
                            'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                        ];
                    }
                }
            }

            $downPayment = $proformaInvoice->down_payment ?? 0;
            $subtotal = $quotation->subtotal ?? 0;
            $subtotalCalculated = $invoice->invoice_type === self::INVOICE_DP1 ? $subtotal * $downPayment / 100 : ($invoice->invoice_type === self::INVOICE_DP2 ? $subtotal * (100-$downPayment) / 100 : $subtotal);
            $grandTotal = $quotation->grand_total ?? 0;
            $grandTotalCalculated = $invoice->invoice_type === self::INVOICE_DP1 ? $grandTotal * $downPayment / 100 : ($invoice->invoice_type === self::INVOICE_DP2 ? $grandTotal * (100-$downPayment) / 100 : $grandTotal);

            $formattedInvoice = [
                'id' => (string) $invoice->id,
                'invoice' => [
                    'invoice_number' => $invoice->invoice_number,
                    'date' => $invoice->invoice_date,
                    'type' => $invoice->invoice_type ?? '',
                    'term_of_payment' => $invoice->term_of_payment ?? '',
                    'subtotal' => $subtotalCalculated,
                    'grand_total' => $grandTotalCalculated,
                    'version' => $invoice->version,
                    'down_payment' => $downPayment,
                ],
                'purchase_order' => [
                    'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                    'po_number' => $purchaseOrder->po_number ?? '',
                    'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                    'purchase_order_type' => $quotation->type ?? '',
                    'payment_due' => $purchaseOrder->payment_due,
                    'discount' => $quotation ? $quotation->discount : ''
                ],
                'customer' => [
                    'company_name' => $customer->company_name ?? '',
                    'address' => $customer->address ?? '',
                    'city' => $customer->city ?? '',
                    'province' => $customer->province ?? '',
                    'office' => $customer->office ?? '',
                    'urban' => $customer->urban ?? '',
                    'subdistrict' => $customer->subdistrict ?? '',
                    'postal_code' => $customer->postal_code ?? ''
                ],
                'price' => [
                    'subtotal' => $quotation->subtotal ?? 0,
                    'ppn' => $quotation->ppn ?? 0,
                    'grand_total' => $quotation->grand_total ?? 0,
                ],
                'status' => $quotation->status ?? [],
                'quotation_number' => $quotation ? $quotation->quotation_number : '',
                'version' => $purchaseOrder->version,
                'notes' => $quotation->notes ?? '',
                'spareparts' => $spareParts,
                'services' => $services,
                'type' => $quotation->type,
            ];

            return response()->json([
                'message' => 'Invoice retrieved successfully',
                'data' => $formattedInvoice,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getAll(Request $request)
    {
        try {
            // Get all invoice numbers first to ensure we capture all versions
            $invoiceNumbers = $this->getAccessedInvoice($request)
                ->select('invoice_number');

            // Get query parameters
            $q = $request->query('search');
            $month = $request->query('month');
            $year = $request->query('year');

            // Apply search term filter if 'q' is provided
            if ($q) {
                $invoiceNumbers->where(function ($invoiceNumbers) use ($q) {
                    $invoiceNumbers->where('invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('proformaInvoice.purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            // Apply year and month filter
            if ($year) {
                $invoiceNumbers->whereYear('invoice_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $invoiceNumbers->whereMonth('invoice_date', $monthNumber);
                }
            }

            // Build base builder with filters
            $baseBuilder = $this->getAccessedInvoice($request);

            if ($q) {
                $baseBuilder->where(function ($base) use ($q) {
                    $base->where('invoice_number', 'like', '%' . $q . '%')
                        ->orWhereHas('proformaInvoice.purchaseOrder.quotation.customer', function ($qry) use ($q) {
                            $qry->where('company_name', 'like', '%' . $q . '%');
                        });
                });
            }

            if ($year) {
                $baseBuilder->whereYear('invoice_date', $year);
                if ($month) {
                    $monthNumber = date('m', strtotime($month));
                    $baseBuilder->whereMonth('invoice_date', $monthNumber);
                }
            }

            // Group to get representative ids per invoice_number
            $grouped = (clone $baseBuilder)
                ->getQuery()
                ->select('invoice_number', DB::raw('MAX(id) as max_id'))
                ->groupBy('invoice_number')
                ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(invoice_number, \'/\', 2), \'/\', -1) AS UNSIGNED) ASC, version ASC');

            $paginatedGroups = DB::table(DB::raw("({$grouped->toSql()}) as grouped"))
                ->mergeBindings($grouped)
                ->select('invoice_number', 'max_id')
                ->paginate(20);

            $groupNumbers = $paginatedGroups->pluck('invoice_number')->filter()->all();

            if (empty($groupNumbers)) {
                return response()->json([
                    'message' => 'List of invoices retrieved successfully',
                    'data' => [
                        'data' => [],
                        'from' => $paginatedGroups->firstItem(),
                        'to' => $paginatedGroups->lastItem(),
                        'total' => $paginatedGroups->total(),
                        'per_page' => $paginatedGroups->perPage(),
                        'current_page' => $paginatedGroups->currentPage(),
                        'last_page' => $paginatedGroups->lastPage(),
                    ]
                ], Response::HTTP_OK);
            }
            // Fetch all Invoice rows for the paginated group numbers and preserve group ordering
            $invoiceOrders = Invoice::with(['proformaInvoice.purchaseOrder.quotation.customer', 'proformaInvoice.purchaseOrder.quotation.detailQuotations.sparepart'])
                ->whereIn('invoice_number', $groupNumbers)
                ->get();

            // Order by the page group order then by version asc within each group
            $ordered = $invoiceOrders->sortBy(function ($inv) use ($groupNumbers) {
                $groupIndex = array_search($inv->invoice_number, $groupNumbers);
                $version = intval($inv->proformaInvoice?->purchaseOrder?->version ?? 0);
                return ($groupIndex !== false ? $groupIndex : 0) * 100000 + $version;
            })->values();

            // Return like API contract
            $invoices = $ordered->map(function ($invoice) {
                $proformaInvoice = $invoice->proformaInvoice;
                if (!$proformaInvoice) return null;
                $purchaseOrder = $proformaInvoice->purchaseOrder;
                if (!$purchaseOrder) return null;
                $quotation = $purchaseOrder->quotation;
                if (!$quotation) return null;
                $customer = $quotation->customer ?? null;

                $spareParts = [];
                $services = [];
                if ($quotation && $quotation->detailQuotations) {
                    foreach ($quotation->detailQuotations as $detail) {
                        if ($detail->sparepart_id) {
                            $sparepart = $detail->sparepart;
                            $spareParts[] = [
                                'sparepart_id' => $sparepart->id ?? '',
                                'sparepart_name' => $sparepart->sparepart_name ?? '',
                                'sparepart_number' => $sparepart->sparepart_number ?? '',
                                'quantity' => $detail->quantity ?? 0,
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                            ];
                        } else {
                            $services[] = [
                                'service' => $detail->service ?? '',
                                'unit_price_sell' => $detail->unit_price ?? 0,
                                'quantity' => $detail->quantity ?? 0,
                                'total_price' => ($detail->quantity * ($detail->unit_price ?? 0))
                            ];
                        }
                    }
                }

                return [
                    'id' => (string) $invoice->id,
                    'invoice' => [
                        'invoice_number' => $invoice->invoice_number,
                        'date' => $invoice->invoice_date,
                        'term_of_payment' => $invoice->term_of_payment ?? '',
                        'type' => $invoice->invoice_type ?? '',
                        'subtotal' => $quotation->subtotal ?? 0,
                        'grand_total' => $quotation->grand_total ?? 0,
                        'version' => $invoice->version,
                    ],
                    'purchase_order' => [
                        'purchase_order_number' => $purchaseOrder->purchase_order_number ?? '',
                        'po_number' => $purchaseOrder->po_number ?? '',
                        'purchase_order_date' => $purchaseOrder->purchase_order_date ?? '',
                        'payment_due' => $purchaseOrder->payment_due,
                        'discount' => $quotation ? $quotation->discount : ''
                    ],
                    'customer' => [
                        'company_name' => $customer->company_name ?? '',
                        'address' => $customer->address ?? '',
                        'city' => $customer->city ?? '',
                        'province' => $customer->province ?? '',
                        'office' => $customer->office ?? '',
                        'urban' => $customer->urban ?? '',
                        'subdistrict' => $customer->subdistrict ?? '',
                        'postal_code' => $customer->postal_code ?? ''
                    ],
                    'price' => [
                        'subtotal' => $quotation->subtotal ?? 0,
                        'ppn' => $quotation->ppn ?? 0,
                        'grand_total' => $quotation->grand_total ?? 0,
                    ],
                    'status' => $quotation->status ?? [],
                    'quotation_number' => $quotation ? $quotation->quotation_number : '',
                    'version' => $purchaseOrder->version,
                    'notes' => $quotation->notes ?? '',
                    'spareparts' => $spareParts,
                    'services' => $services,
                    'type' => $quotation->type,
                ];
            });

            $invoices = $invoices->filter()->values();

            return response()->json([
                'message' => 'List of invoices retrieved successfully',
                'data' => [
                    'data' => $invoices,
                    'from' => $paginatedGroups->firstItem(),
                    'to' => $paginatedGroups->lastItem(),
                    'total' => $paginatedGroups->total(),
                    'per_page' => $paginatedGroups->perPage(),
                    'current_page' => $paginatedGroups->currentPage(),
                    'last_page' => $paginatedGroups->lastPage(),
                ]
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function setInvoiceType(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $invoice = Invoice::findOrFail($id);
            $type = $request->input('type');

            if (!in_array($type, [self::INVOICE_DP1, self::INVOICE_FINAL, self::INVOICE_DP2])) {
                return response()->json([
                    'message' => 'Invalid invoice type. Allowed values are "' . self::INVOICE_DP1 . '", "' . self::INVOICE_DP2 . '" or "' . self::INVOICE_FINAL . '".'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Find the latest versioned invoice for this proforma invoice
            $latestInvoice = Invoice::where('proforma_invoice_id', $invoice->proforma_invoice_id)
                ->orderBy('version', 'desc')
                ->lockForUpdate()
                ->first();

            if(($type === self::INVOICE_DP1 && $latestInvoice->invoice_type != self::INVOICE_DP1) || $type === self::INVOICE_FINAL) {
                if ($latestInvoice->version > 0) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Cannot create DP1 invoice. An invoice version already exists.'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $latestVersion = $latestInvoice ? $latestInvoice->version : 0;
                $newVersion = $latestVersion + 1;
                $newInvoiceNumber = $latestInvoice->invoice_number;

                $newInvoice = Invoice::create([
                    'proforma_invoice_id' => $invoice->proforma_invoice_id,
                    'invoice_number' => $newInvoiceNumber,
                    'invoice_date' => now(),
                    'invoice_type' => $type,
                    'version' => $newVersion,
                    'employee_id' => $invoice->employee_id,
                    'term_of_payment' => $invoice->term_of_payment,
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Invoice created successfully',
                    'data' => [
                        'id' => (string) $newInvoice->id,
                        'invoice_number' => $newInvoice->invoice_number,
                        'invoice_type' => $newInvoice->invoice_type,
                        'version' => $newInvoice->version,
                    ]
                ], Response::HTTP_OK);
            }
            else {
                if ($latestInvoice->invoice_type != self::INVOICE_DP1) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Cannot create DP2 invoice. A DP1 invoice version must exist and be the latest version.'
                    ], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $latestVersion = $latestInvoice ? $latestInvoice->version : 0;
                $newVersion = $latestVersion + 1;

                $parts = explode('/', $latestInvoice->invoice_number);
                $number = intval($parts[1] ?? 0);
                $parts[1] = str_pad($number + 1, 1, '0', STR_PAD_LEFT);
                $newInvoiceNumber = implode('/', $parts);

                $newInvoice = Invoice::create([
                    'proforma_invoice_id' => $invoice->proforma_invoice_id,
                    'invoice_number' => $newInvoiceNumber,
                    'invoice_date' => now(),
                    'invoice_type' => self::INVOICE_DP2,
                    'version' => $newVersion,
                    'employee_id' => $invoice->employee_id,
                    'term_of_payment' => $invoice->term_of_payment,
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Invoice created successfully',
                    'data' => [
                        'id' => (string) $newInvoice->id,
                        'invoice_number' => $newInvoice->invoice_number,
                        'invoice_type' => $newInvoice->invoice_type,
                        'version' => $newInvoice->version,
                    ]
                ], Response::HTTP_OK);
            }
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th);
        }
    }

    protected function getAccessedInvoice($request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $role = $user->role;

            $query = Invoice::query();

            // Only allow invoices for authorized users
            if ($role == 'Marketing') {
                $query->where('employee_id', $userId);
            }

            return $query;
        } catch (\Throwable $th) {
            // Return empty query builder
            return Invoice::whereNull('id');
        }
    }

    // Helper methods for consistent error handling
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

    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message
        ], Response::HTTP_NOT_FOUND);
    }
}
