<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
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
            }

            // Cleanup final file
            unlink($finalPath);
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
        // Start transaction
        DB::beginTransaction();
        try {
            // Validate input data
            $validator = Validator::make($request->all(), [
                'sparepartNumber' => 'required|string|max:255',
                'sparepartName' => 'required|string|max:255',
                'totalUnit' => 'required|integer|min:0',
                'unitPriceBuy' => 'nullable|numeric|min:0',
                'unitPriceSell' => 'required|numeric|min:0',
                'branch' => 'required|string|max:255',
                'unitPriceSeller' => 'present|array',
                'unitPriceSeller.*.seller' => 'required|string|max:255',
                'unitPriceSeller.*.price' => 'required|numeric|min:0',
                'unitPriceSeller.*.quantity' => 'required|integer|min:0'
            ]);

            // Map camelCase to snake_case
            $data = [
                'sparepart_number' => $request->input('sparepartNumber', ''),
                'sparepart_name' => $request->input('sparepartName', ''),
                'total_unit' => $request->input('totalUnit'),
                'branch' => $request->input('branch'),
                'unit_price_buy' => $request->input('unitPriceBuy'),
                'unit_price_sell' => $request->input('unitPriceSell'),
                'branch' => $request->input('branch', null),
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

            // Create or find sellers and create detail spareparts
            foreach ($data['unit_price_seller'] as $buy) {
                $seller = Seller::where('name', $buy['seller'])->first();
                if (!$seller) {
                    $seller = Seller::create([
                        'name' => $buy['seller'],
                        'type' => null, // Default to null as type is not provided
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
        DB::beginTransaction();
        try {
            // Find sparepart and lock it for update
            $sparepart = Sparepart::with('detailSpareparts.seller')->lockForUpdate()->findOrFail($id);

            // Validate input data
            $validator = Validator::make($request->all(), [
                'sparepartNumber' => 'required|string|max:255',
                'sparepartName' => 'required|string|max:255',
                'totalUnit' => 'required|integer|min:0',
                'unitPriceBuy' => 'nullable|numeric|min:0',
                'unitPriceSell' => 'required|numeric|min:0',
                'branch' => 'required|string|max:255',
                'unitPriceSeller' => 'present|array',
                'unitPriceSeller.*.seller' => 'required|string|max:255',
                'unitPriceSeller.*.price' => 'required|numeric|min:0',
                'unitPriceSeller.*.quantity' => 'required|integer|min:0',
            ]);

            // Map camelCase to snake_case
            $data = [
                'sparepart_number' => $request->input('sparepartNumber', ''),
                'sparepart_name' => $request->input('sparepartName', ''),
                'total_unit' => $request->input('totalUnit'),
                'unit_price_buy' => $request->input('unitPriceBuy'),
                'unit_price_sell' => $request->input('unitPriceSell'),
                'unit_price_seller' => $request->input('unitPriceSeller', []),
                'branch' => $request->input('branch', $sparepart->branch),
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

            // Delete old detail spareparts
            $sparepart->detailSpareparts()->delete();

            // Create new detail spareparts
            foreach ($data['unit_price_seller'] as $buy) {
                $seller = Seller::where('name', $buy['seller'])->first();
                if (!$seller) {
                    $seller = Seller::create([
                        'name' => $buy['seller'],
                        'type' => null, // Default to null as type is not provided
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
            $formattedSparepart = $this->formatSparepartResponse($sparepart->fresh(['detailSpareparts.seller']), $request->user());

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
            $sparepart = $spareparts->with('detailSpareparts.seller')
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

            $sparepartsQuery = $spareparts->where(function ($query) use ($q) {
                $query->where('sparepart_name', 'like', "%$q%")
                    ->orWhere('sparepart_number', 'like', "%$q%");
            })
                ->with('detailSpareparts.seller');

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
        $response = [
            'id' => $sparepart->id ?? '',
            'slug' => $sparepart->slug ?? '',
            'sparepart_number' => $sparepart->sparepart_number ?? '',
            'sparepart_name' => $sparepart->sparepart_name ?? '',
            'total_unit' => $sparepart->total_unit,
            'branch' => $sparepart->branch,
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
        ];

        // Hide unitPriceSell for Inventory role
        if ($user->role === 'Inventory') {
            $response['unitPriceSell'] = null;
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

            if ($role == 'Inventory') {
                // Hide the 'unit_price_sell' field for Inventory role
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

    // Helper methods for consistent error handling
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
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
