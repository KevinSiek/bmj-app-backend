<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use App\Models\StockMovement;
use App\Models\DetailSparepart;
use App\Models\Seller;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\Buy;
use App\Models\Borrow;
use App\Models\BackOrder;
use App\Services\SparepartStockService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Imports\SparepartImport;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SparepartController extends Controller
{
    protected SparepartStockService $stockService;

    public function __construct(SparepartStockService $stockService)
    {
        $this->stockService = $stockService;
    }

    /**
     * Rewrite all spareparts from an uploaded Excel or CSV file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAllData(Request $request)
    {
        // TODO: This bulk update operation is highly susceptible to race conditions if multiple users
        // upload files simultaneously. An application-level lock (e.g., using Redis, a database flag, or a queue)
        // should be implemented to ensure only one import process can run at a time. Ignored for now as per instructions.
        try {
            // Validate file upload
            $validator = Validator::make($request->all(), [
                'file' => 'required|mimes:xlsx,xls,csv',
            ]);

            if ($validator->fails()) {
                return response(
                    $validator->errors()->first('file'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $import = new SparepartImport();

            // Start transaction
            DB::beginTransaction();

            try {
                Excel::import($import, $request->file('file'));
                DB::commit();

                return response()->json([
                    'message' => 'Spareparts data updated successfully',
                    'data' => [
                        'new_records' => $import->getSuccessCount(),
                        'updated_records' => $import->getUpdateCount(),
                    ],
                ], Response::HTTP_OK);
            } catch (ValidationException $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Validation error in Excel file',
                    'errors' => $e->errors(),
                ], Response::HTTP_BAD_REQUEST);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Error processing spareparts data');
        }
    }

    public function uploadFile (Request $request)
    {
        // Log::info('Chunk received', [
        //     'uuid' => $request->dzuuid,
        //     'chunkIndex' => $request->dzchunkindex,
        //     'totalChunks' => $request->dztotalchunkcount,
        //     'fileExists' => file_exists(storage_path("app/uploads/chunks/{$request->dzuuid}/{$request->dzchunkindex}")),
        // ]);
        try {
             // Dropzone chunk info
            $uuid = $request->get('dzuuid');
            $chunkIndex = (int) $request->get('dzchunkindex', 0);
            $totalChunks = (int) $request->get('dztotalchunkcount', 1);
            $file = $request->file('file');

            // Temporary folder for chunks
            $chunkPath = storage_path("app/uploads/chunks/{$uuid}");
            if (!file_exists($chunkPath)) {
                mkdir($chunkPath, 0777, true);
            }

            // Move uploaded chunk to temporary folder
            $fileName = $chunkIndex . '.part';
            $file->move($chunkPath, $fileName);

            if ($chunkIndex + 1 < $totalChunks) {
                return response()->json(['message' => "Chunk {$chunkIndex} uploaded"]);
            }

            // Last chunk received → merge all chunks
            $finalPath = storage_path("app/uploads/chunks/{$uuid}.xlsx");
            $output = fopen($finalPath, 'ab');

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkFile = $chunkPath . '/' . $i . '.part';
                if (!file_exists($chunkFile)) {
                    return response()->json(['error' => "Missing chunk {$i}"], 422);
                }

                $chunk = file_get_contents($chunkFile);
                fwrite($output, $chunk);
            }

            fclose($output);

            // Clean up temporary chunks
            array_map('unlink', glob("$chunkPath/*"));
            rmdir($chunkPath);

            $import = new SparepartImport();

            // Start transaction
            DB::beginTransaction();

            try {
                Excel::import($import, $finalPath);
                DB::commit();

                return response()->json([
                    'message' => 'Spareparts data updated successfully',
                    'data' => [
                        'new_records' => $import->getNewCount(),
                        'updated_records' => $import->getUpdateCount(),
                    ],
                ], Response::HTTP_OK);
            } catch (ValidationException $e) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Validation error in Excel file',
                    'errors' => $e->errors(),
                ], Response::HTTP_BAD_REQUEST);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            } finally {
                @unlink($finalPath);
            }
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Error processing spareparts data');
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $sparepart = Sparepart::lockForUpdate()->find($id);

            if (!$sparepart) {
                DB::rollBack();
                return $this->handleNotFound('Sparepart not found');
            }

            $sparepart->delete();
            DB::commit();

            return response()->json([
                'message' => 'Sparepart deleted successfully',
                'data' => null,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Sparepart deletion failed');
        }
    }

    /**
     * Store a new sparepart.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate outside transaction so a 422 is not swallowed as 500
        $request->validate([
            'sparepartNumber' => 'required|string|max:255',
            'sparepartName' => 'required|string|max:255',
            'totalUnit' => 'required|array',
            'totalUnit.*.name' => 'required|string|max:255',
            'totalUnit.*.stock' => 'required|integer|min:0',
            'unitPriceBuy' => 'nullable|numeric|min:0',
            'unitPriceSell' => 'required|numeric|min:0',
            'unitPriceSeller' => 'present|array',
            'unitPriceSeller.*.seller' => 'required|string|max:255',
            'unitPriceSeller.*.price' => 'required|numeric|min:0',
            'unitPriceSeller.*.quantity' => 'required|integer|min:0'
        ]);

        // Start transaction
        DB::beginTransaction();
        try {

            // Map camelCase to snake_case
            $data = [
                'sparepart_number' => $request->input('sparepartNumber', ''),
                'sparepart_name' => $request->input('sparepartName', ''),
                'unit_price_buy' => $request->input('unitPriceBuy'),
                'unit_price_sell' => $request->input('unitPriceSell'),
                'unit_price_seller' => $request->input('unitPriceSeller', []),
            ];

            // Check if sparepart with this number already exists
            if (Sparepart::where('sparepart_number', $data['sparepart_number'])->exists()) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Sparepart already exists, please use edit function',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate slug from sparepart_number
            $baseSlug = Str::slug($data['sparepart_number']);
            $slug = $baseSlug;
            $counter = 1;
            while (Sparepart::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }
            $data['slug'] = $slug;

            // Create sparepart
            $sparepart = Sparepart::create($data);

            foreach ($request->input('totalUnit', []) as $unitData) {
                $branchModel = $this->resolveBranchModel($unitData['name']);
                if ($branchModel) {
                    $this->setStockForBranch($sparepart, $branchModel->id, (int) $unitData['stock'], $request->user()?->id);
                }
            }

            // Create or find sellers and create detail spareparts
            foreach ($data['unit_price_seller'] as $buy) {
                $seller = Seller::where('name', $buy['seller'])->first();
                if (!$seller) {
                    $seller = Seller::create([
                        'name' => $buy['seller'],
                        'slug' => Str::slug($buy['seller']) . '-' . Str::random(6),
                        'type' => null,
                    ]);
                }

                DetailSparepart::create([
                    'sparepart_id' => $sparepart->id,
                    'seller_id' => $seller->id,
                    'unit_price' => $buy['price'],
                    'quantity' => $buy['quantity'] ?? 0,
                ]);
            }

            DB::commit();

            // Prepare response data
            $formattedSparepart = $this->formatSparepartResponse($sparepart, $request->user());

            return response()->json([
                'message' => 'Sparepart created successfully',
                'data' => $formattedSparepart,
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Error creating sparepart');
        }
    }

    /**
     * Update an existing sparepart.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        // Validate outside transaction so a 422 is not swallowed as 500
        $request->validate([
            'sparepartNumber' => 'required|string|max:255',
            'sparepartName' => 'required|string|max:255',
            'totalUnit' => 'required|array',
            'totalUnit.*.name' => 'required|string|max:255',
            'totalUnit.*.stock' => 'required|integer',
            'unitPriceBuy' => 'nullable|numeric|min:0',
            'unitPriceSell' => 'required|numeric|min:0',
            'unitPriceSeller' => 'present|array',
            'unitPriceSeller.*.seller' => 'required|string|max:255',
            'unitPriceSeller.*.price' => 'required|numeric|min:0',
            'unitPriceSeller.*.quantity' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Find sparepart and lock it for update
            $sparepart = Sparepart::with(['detailSpareparts.seller', 'branchStocks.branch'])->lockForUpdate()->findOrFail($id);

            // Map camelCase to snake_case
            $data = [
                'sparepart_number' => $request->input('sparepartNumber', ''),
                'sparepart_name' => $request->input('sparepartName', ''),
                'unit_price_buy' => $request->input('unitPriceBuy'),
                'unit_price_sell' => $request->input('unitPriceSell'),
                'unit_price_seller' => $request->input('unitPriceSeller', []),
            ];

            // Check if sparepart_number changed and already exists
            if (
                $data['sparepart_number'] !== $sparepart->sparepart_number &&
                Sparepart::where('sparepart_number', $data['sparepart_number'])->exists()
            ) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Sparepart number already exists',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Generate new slug if sparepart_name changed
            if ($data['sparepart_name'] !== $sparepart->sparepart_name) {
                $baseSlug = Str::slug($data['sparepart_name']);
                $slug = $baseSlug;
                $counter = 1;
                while (Sparepart::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                    $slug = $baseSlug . '-' . $counter++;
                }
                $data['slug'] = $slug;
            } else {
                $data['slug'] = $sparepart->slug;
            }

            // Update sparepart
            $sparepart->update($data);

            foreach ($request->input('totalUnit', []) as $unitData) {
                $branchModel = $this->resolveBranchModel($unitData['name']);
                if ($branchModel) {
                    $this->setStockForBranch($sparepart, $branchModel->id, (int) $unitData['stock'], $request->user()?->id);
                }
            }

            // Delete old detail spareparts
            $sparepart->detailSpareparts()->delete();

            // Create new detail spareparts
            foreach ($data['unit_price_seller'] as $buy) {
                $seller = Seller::where('name', $buy['seller'])->first();
                if (!$seller) {
                    $seller = Seller::create([
                        'name' => $buy['seller'],
                        'slug' => Str::slug($buy['seller']) . '-' . Str::random(6),
                        'type' => null,
                    ]);
                }

                DetailSparepart::updateOrCreate([
                    'sparepart_id' => $sparepart->id,
                    'seller_id' => $seller->id
                ],[
                    'unit_price' => $buy['price'],
                    'quantity' => $buy['quantity'] ?? 0,
                ]);
            }

            DB::commit();

            // Prepare response data
            $formattedSparepart = $this->formatSparepartResponse($sparepart->fresh(['detailSpareparts.seller', 'branchStocks.branch']), $request->user());

            return response()->json([
                'message' => 'Sparepart updated successfully',
                'data' => $formattedSparepart,
            ], Response::HTTP_OK);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return $this->handleNotFound('Sparepart not found');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->handleError($th, 'Error updating sparepart');
        }
    }

    /**
     * Retrieve a single sparepart by ID.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request, $id)
    {
        try {
            $spareparts = $this->getAccessedSparepart($request);
            $sparepart = $spareparts->with(['detailSpareparts.seller', 'branchStocks.branch'])
                ->where('id', $id)
                ->firstOrFail();

            $formattedSparepart = $this->formatSparepartResponse($sparepart, $request->user());

            return response()->json([
                'message' => 'Sparepart retrieved successfully',
                'data' => $formattedSparepart,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Retrieve all spareparts with pagination and search.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAll(Request $request)
    {
        try {
            $q = $request->query('search');
            $spareparts = $this->getAccessedSparepart($request);

            if ($q) {
                $spareparts->where(function ($query) use ($q) {
                    $query->where('sparepart_name', 'like', "%$q%")
                        ->orWhere('sparepart_number', 'like', "%$q%");
                });
            }

            $sparepartsQuery = $spareparts->with(['detailSpareparts.seller', 'branchStocks.branch']);

            $paginatedSpareparts = $sparepartsQuery->paginate(20)->through(function ($data) use ($request) {
                return $this->formatSparepartResponse($data, $request->user());
            });

            return response()->json([
                'message' => 'List of all spareparts retrieved successfully',
                'data' => $paginatedSpareparts,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    /**
     * Format sparepart data for API response, respecting user role.
     *
     * @param Sparepart $sparepart
     * @param \App\Models\User $user
     * @return array
     */
    protected function formatSparepartResponse($sparepart, $user)
    {
        $branchId = $this->resolveBranchIdForUser($user);
        $stocks = $sparepart->branchStocks->map(function ($stock) {
            return [
                'branch_id' => $stock->branch_id,
                'branch' => $stock->branch->name ?? '',
                'branch_code' => $stock->branch->code ?? '',
                'quantity' => $stock->quantity ?? 0,
            ];
        });

        $branchStock = $branchId ? $stocks->firstWhere('branch_id', $branchId) : null;
        $totalUnit = $branchStock['quantity'] ?? $stocks->sum('quantity');
        $totalUnitByBranch = $stocks->map(function ($stock) {
            return [
                'name' => $stock['branch'],
                'stock' => (int) ($stock['quantity'] ?? 0),
            ];
        })->values()->toArray();

        $response = [
            'id' => $sparepart->id ?? '',
            'slug' => $sparepart->slug ?? '',
            'sparepart_number' => $sparepart->sparepart_number ?? '',
            'sparepart_name' => $sparepart->sparepart_name ?? '',
            // 'total_unit' => $totalUnit,
            'total_unit' => $totalUnitByBranch,
            'unit_price_buy' => $sparepart->unit_price_buy,
            'unit_price_sell' => $sparepart->unit_price_sell,
            'unit_price_seller' => $sparepart->detailSpareparts->map(function ($detail) {
                return [
                    'seller_id' => $detail->seller->id ?? '',
                    'seller' => $detail->seller->name ?? '',
                    'price' => $detail->unit_price ?? 0,
                    'quantity' => $detail->quantity ?? 0,
                ];
            })->toArray(),
            'stocks' => $stocks->values()->toArray(),
        ];

        // Hide the sell price from Inventory Admin / Purchase (they don't need margin info).
        // NOTE: the response key is snake_case (unit_price_sell), not unitPriceSell.
        if ($user->role === 'Inventory Admin' || $user->role === 'Inventory Purchase' || $user->role === 'Head Inventory') {
            $response['unit_price_sell'] = null;
        }

        // Marketing may browse spareparts but must NOT see costing: hide buy price
        // and the seller list entirely. They keep number, name, sell price, and stock.
        if ($user->role === 'Marketing') {
            $response['unit_price_buy'] = null;
            $response['unit_price_seller'] = [];
        }

        return $response;
    }

    /**
     * Get spareparts query based on user access role.
     *
     * @param Request $request
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getAccessedSparepart($request)
    {
        // Prevent unauthorized user to get buy or sell price of sparepart
        try {
            $user = $request->user();
            $role = $user->role;

            if ($role == 'Inventory Admin' || $role == 'Inventory Purchase' || $role == 'Head Inventory') {
                // Hide the 'unit_price_sell' field for Inventory Admin, Inventory Purchase, and Head Inventory roles
                $spareparts = Sparepart::query()->select('*')->addSelect(['unit_price_sell' => function ($query) {
                    $query->selectRaw('NULL');
                }]);
            } else {
                $spareparts = Sparepart::query();
            }

            // Return the query builder instance
            return $spareparts;
        } catch (\Throwable $th) {
            return Sparepart::query(); // Fallback to prevent breaking the flow
        }
    }

    protected function resolveBranchModel(?string $value): ?Branch
    {
        if (!$value) {
            return null;
        }

        $normalized = strtolower($value);

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw('LOWER(code) = ?', [$normalized])
            ->first();
    }

    protected function resolveBranchIdForUser($user): ?int
    {
        if (!$user) {
            return null;
        }

        $branch = $user->branch;

        return $branch?->id;
    }

    protected function setStockForBranch(Sparepart $sparepart, $branch, int $quantity, ?int $employeeId = null): void
    {
        $record = $this->stockService->ensureStockRecord($sparepart, $branch, true);
        $oldQuantity = (int) $record->quantity;
        $newQuantity = $quantity;
        $record->quantity = $newQuantity;
        $record->save();

        // This SETS an absolute quantity rather than applying a delta, so log the net change
        // explicitly to keep the ledger complete.
        $delta = $newQuantity - $oldQuantity;
        if ($delta !== 0) {
            $this->stockService->logMovement(
                $sparepart,
                $record->branch_id,
                $delta,
                'ManualEdit',
                $sparepart->id,
                $employeeId,
                'Manual stock edit'
            );
        }
    }

    // Global stock movement ledger across ALL spareparts — backs the standalone Stock History
    // page. Inventory + Director only. Supports filters:
    //   search (sparepart name/number), branch (id or name), source_type, month + year.
    public function stockMovements(Request $request)
    {
        try {
            $query = StockMovement::with(['sparepart', 'branch', 'employee'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $filterType = $request->query('filter_type');
            $filterId = $request->query('filter_id');

            if ($filterType && $filterId) {
                if ($filterType === 'Sparepart') {
                    $query->where('sparepart_id', $filterId);
                } elseif (in_array($filterType, ['Buy', 'Borrow'])) {
                    $query->where('source_type', $filterType)
                          ->where('source_id', $filterId);
                } elseif ($filterType === 'PurchaseOrder') {
                    $poIds = collect([$filterId]);
                    $boIds = BackOrder::whereIn('purchase_order_id', $poIds)->pluck('id');
                    $buyIds = Buy::whereIn('back_order_id', $boIds)->pluck('id');
                    $borrowIds = Borrow::whereIn('purchase_order_id', $poIds)->pluck('id');

                    $query->where(function($q) use ($poIds, $boIds, $buyIds, $borrowIds) {
                        $q->where(function($sub) use ($poIds) {
                            $sub->where('source_type', 'PurchaseOrder')->whereIn('source_id', $poIds);
                        })->orWhere(function($sub) use ($poIds) {
                            $sub->where('source_type', 'Return')->whereIn('source_id', $poIds);
                        })->orWhere(function($sub) use ($boIds) {
                            $sub->where('source_type', 'BackOrder')->whereIn('source_id', $boIds);
                        })->orWhere(function($sub) use ($buyIds) {
                            $sub->where('source_type', 'Buy')->whereIn('source_id', $buyIds);
                        })->orWhere(function($sub) use ($borrowIds) {
                            $sub->where('source_type', 'Borrow')->whereIn('source_id', $borrowIds);
                        });
                    });
                } elseif ($filterType === 'Customer') {
                    $poIds = PurchaseOrder::whereHas('quotation', function($q) use ($filterId) {
                        $q->where('customer_id', $filterId);
                    })->pluck('id');

                    $boIds = BackOrder::whereIn('purchase_order_id', $poIds)->pluck('id');
                    $buyIds = Buy::whereIn('back_order_id', $boIds)->pluck('id');
                    $borrowIds = Borrow::whereIn('purchase_order_id', $poIds)->pluck('id');

                    $query->where(function($q) use ($poIds, $boIds, $buyIds, $borrowIds) {
                        $q->where(function($sub) use ($poIds) {
                            $sub->where('source_type', 'PurchaseOrder')->whereIn('source_id', $poIds);
                        })->orWhere(function($sub) use ($poIds) {
                            $sub->where('source_type', 'Return')->whereIn('source_id', $poIds);
                        })->orWhere(function($sub) use ($boIds) {
                            $sub->where('source_type', 'BackOrder')->whereIn('source_id', $boIds);
                        })->orWhere(function($sub) use ($buyIds) {
                            $sub->where('source_type', 'Buy')->whereIn('source_id', $buyIds);
                        })->orWhere(function($sub) use ($borrowIds) {
                            $sub->where('source_type', 'Borrow')->whereIn('source_id', $borrowIds);
                        });
                    });
                } elseif ($filterType === 'Employee') {
                    $query->where('employee_id', $filterId);
                }
            } elseif ($search = $request->query('search')) {
                // Fallback text search across multiple entities
                $query->where(function ($q) use ($search) {
                    $q->whereHas('sparepart', function ($sub) use ($search) {
                        $sub->where('sparepart_name', 'like', "%{$search}%")
                            ->orWhere('sparepart_number', 'like', "%{$search}%");
                    });

                    $q->orWhereHas('employee', function ($sub) use ($search) {
                        $sub->where('fullname', 'like', "%{$search}%");
                    });

                    $poIdsByCustomer = PurchaseOrder::whereHas('quotation.customer', function($sub) use ($search) {
                        $sub->where('company_name', 'like', "%{$search}%");
                    })->pluck('id');

                    $poIdsByNumber = PurchaseOrder::where('purchase_order_number', 'like', "%{$search}%")->pluck('id');

                    $poIds = $poIdsByCustomer->merge($poIdsByNumber)->unique();

                    if ($poIds->isNotEmpty()) {
                        $boIds = BackOrder::whereIn('purchase_order_id', $poIds)->pluck('id');
                        $buyIds = Buy::whereIn('back_order_id', $boIds)->pluck('id');
                        $borrowIds = Borrow::whereIn('purchase_order_id', $poIds)->pluck('id');

                        $q->orWhere(function($sub) use ($poIds, $boIds, $buyIds, $borrowIds) {
                            $sub->where(function($s) use ($poIds) {
                                $s->where('source_type', 'PurchaseOrder')->whereIn('source_id', $poIds);
                            })->orWhere(function($s) use ($poIds) {
                                $s->where('source_type', 'Return')->whereIn('source_id', $poIds);
                            })->orWhere(function($s) use ($boIds) {
                                $s->where('source_type', 'BackOrder')->whereIn('source_id', $boIds);
                            })->orWhere(function($s) use ($buyIds) {
                                $s->where('source_type', 'Buy')->whereIn('source_id', $buyIds);
                            })->orWhere(function($s) use ($borrowIds) {
                                $s->where('source_type', 'Borrow')->whereIn('source_id', $borrowIds);
                            });
                        });
                    }

                    $directBuyIds = Buy::where('buy_number', 'like', "%{$search}%")->pluck('id');
                    if ($directBuyIds->isNotEmpty()) {
                        $q->orWhere(function($sub) use ($directBuyIds) {
                            $sub->where('source_type', 'Buy')->whereIn('source_id', $directBuyIds);
                        });
                    }

                    $directBorrowIds = Borrow::where('borrow_number', 'like', "%{$search}%")->pluck('id');
                    if ($directBorrowIds->isNotEmpty()) {
                        $q->orWhere(function($sub) use ($directBorrowIds) {
                            $sub->where('source_type', 'Borrow')->whereIn('source_id', $directBorrowIds);
                        });
                    }
                });
            }

            if ($branch = $request->query('branch')) {
                if (is_numeric($branch)) {
                    $query->where('branch_id', (int) $branch);
                } else {
                    $query->whereHas('branch', function ($q) use ($branch) {
                        $q->whereRaw('LOWER(name) = ?', [strtolower($branch)]);
                    });
                }
            }

            if ($sourceType = $request->query('source_type')) {
                $query->where('source_type', $sourceType);
            }

            if ($startDate = $request->query('start_date')) {
                // Ensure the start date includes the beginning of the day
                $query->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime($startDate)));
            }
            if ($endDate = $request->query('end_date')) {
                // Ensure the end date includes the end of the day
                $query->where('created_at', '<=', date('Y-m-d 23:59:59', strtotime($endDate)));
            }

            if (!$startDate && !$endDate) {
                // Fallback to legacy month/year if no date range is provided
                if ($year = $request->query('year')) {
                    $query->whereYear('created_at', $year);
                    if ($month = $request->query('month')) {
                        $monthNumber = is_numeric($month) ? $month : date('m', strtotime($month));
                        $query->whereMonth('created_at', $monthNumber);
                    }
                }
            }

            $movements = $query->paginate(20)->through(function ($movement) {
                return [
                    'id' => $movement->id,
                    'delta' => $movement->delta,
                    'source_type' => $movement->source_type,
                    'source_id' => $movement->source_id,
                    'reason' => $movement->reason,
                    'sparepart' => $movement->sparepart ? [
                        'id' => $movement->sparepart->id,
                        'sparepart_name' => $movement->sparepart->sparepart_name,
                        'sparepart_number' => $movement->sparepart->sparepart_number,
                    ] : null,
                    'branch' => $movement->branch ? [
                        'id' => $movement->branch->id,
                        'name' => $movement->branch->name,
                    ] : null,
                    'employee' => $movement->employee ? [
                        'id' => $movement->employee->id,
                        'name' => $movement->employee->fullname ?? $movement->employee->username,
                    ] : null,
                    'created_at' => $movement->created_at,
                ];
            });

            return response()->json([
                'message' => 'Stock movements retrieved successfully',
                'data' => $movements,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Error retrieving stock movements');
        }
    }

    public function stockMovementSuggestions(Request $request)
    {
        try {
            $q = $request->query('q');
            if (!$q) {
                return response()->json(['message' => 'Query is required', 'data' => []], Response::HTTP_OK);
            }

            $suggestions = collect();

            // 1. Spareparts
            $spareparts = Sparepart::where('sparepart_name', 'like', "%{$q}%")
                ->orWhere('sparepart_number', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($spareparts as $sp) {
                $suggestions->push([
                    'type' => 'Sparepart',
                    'id' => $sp->id,
                    'label' => "Sparepart: {$sp->sparepart_name} ({$sp->sparepart_number})",
                ]);
            }

            // 2. Purchase Orders
            $pos = PurchaseOrder::where('purchase_order_number', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($pos as $po) {
                $suggestions->push([
                    'type' => 'PurchaseOrder',
                    'id' => $po->id,
                    'label' => "PO: {$po->purchase_order_number}",
                ]);
            }

            // 3. Buys
            $buys = Buy::where('buy_number', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($buys as $buy) {
                $suggestions->push([
                    'type' => 'Buy',
                    'id' => $buy->id,
                    'label' => "Buy: {$buy->buy_number}",
                ]);
            }

            // 4. Borrows
            $borrows = Borrow::where('borrow_number', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($borrows as $borrow) {
                $suggestions->push([
                    'type' => 'Borrow',
                    'id' => $borrow->id,
                    'label' => "Borrow: {$borrow->borrow_number}",
                ]);
            }

            // 5. Customers (via PO)
            $customers = \App\Models\Customer::where('company_name', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($customers as $customer) {
                $suggestions->push([
                    'type' => 'Customer',
                    'id' => $customer->id,
                    'label' => "Customer: {$customer->company_name}",
                ]);
            }

            // 6. Employees
            $employees = \App\Models\Employee::where('fullname', 'like', "%{$q}%")
                ->take(5)
                ->get();
            foreach ($employees as $employee) {
                $suggestions->push([
                    'type' => 'Employee',
                    'id' => $employee->id,
                    'label' => "Employee: {$employee->fullname}",
                ]);
            }

            return response()->json([
                'message' => 'Suggestions retrieved successfully',
                'data' => $suggestions->toArray(),
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Error retrieving suggestions');
        }
    }

    public function getSellers($id)
    {
        try {
            $sellers = DetailSparepart::with('seller')
                ->where('sparepart_id', $id)
                ->whereNotNull('seller_id')
                ->get()
                ->map(fn($detail) => [
                    'seller' => $detail->seller?->name,
                    'price'  => $detail->unit_price,
                ])
                ->values();

            return response()->json([
                'message' => 'Sellers retrieved successfully',
                'data' => $sellers,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
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
            'error' => $th->getMessage(),
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    protected function handleNotFound($message = 'Resource not found')
    {
        return response()->json([
            'message' => $message,
        ], Response::HTTP_NOT_FOUND);
    }
}
