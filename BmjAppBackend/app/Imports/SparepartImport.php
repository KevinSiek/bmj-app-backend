<?php

namespace App\Imports;

use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
use App\Models\Branch;
use App\Services\SparepartStockService;
// use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
// use Maatwebsite\Excel\Concerns\WithBatchInserts;

// class SparepartImport implements ToModel, WithHeadingRow, WithChunkReading
// {
//     private $successCount = 0;
//     private $updateCount = 0;

//     private const BRANCH_MAP = [
//         'SMG' => 'Semarang',
//         'JKT' => 'Jakarta'
//     ];

//     // public function batchSize(): int
//     // {
//     //     return 1000; // Process 1000 rows at a time
//     // }

//      public function chunkSize(): int
//     {
//         return 2000; // read 2000 rows from file at once
//     }

//     /**
//      * @param array $row
//      *
//      * @return \Illuminate\Database\Eloquent\Model|null
//      */
//     public function model(array $row)
//     {
//         // Check if the row is empty (all relevant fields are null or empty)
//         $relevantFields = ['sparepart_number', 'sparepart_name', 'purchase_price', 'seller'];
//         $isEmpty = true;
//         foreach ($relevantFields as $field) {
//             if (!empty($row[$field])) {
//                 $isEmpty = false;
//                 break;
//             }
//         }

//         if ($isEmpty) {
//             Log::info("Skipping empty row: " . json_encode($row));
//             return null; // Skip empty rows
//         }

//         // Validate the row manually
//         $validator = Validator::make($row, [
//             'sparepart_number' => 'required|string',
//             'sparepart_name' => 'required|string',
//             'purchase_price' => 'required|numeric|min:0',
//             'seller' => 'nullable',
//             'branch' => 'nullable',
//         ]);


//         if ($validator->fails()) {
//             throw ValidationException::withMessages($validator->errors()->toArray());
//         }

//         // Find existing sparepart by part_number (mapped to sparepart_number)
//         $existingSparepart = Sparepart::where('sparepart_number', $row['sparepart_number'])
//             ->first();

//         // Prepare sparepart data
//         $sparepartData = [
//             'slug' => Str::slug($row['sparepart_number']),
//             'sparepart_number' => $row['sparepart_number'],
//             'sparepart_name' => $row['sparepart_name'],
//             'unit_price_buy' => $row['purchase_price'],
//             'unit_price_sell' => $row['purchase_price'],
//             'branch' => isset($row['branch']) && array_key_exists($row['branch'], self::BRANCH_MAP)
//                 ? self::BRANCH_MAP[$row['branch']]
//                 : 'Semarang',
//             'total_unit' => 0, // Default to 0 since not provided
//         ];

//         $sparepart = null;

//         // Save the sparepart and handle detail spareparts
//         DB::transaction(function () use ($existingSparepart, $sparepartData, $row, &$sparepart) {
//             // Create or update sparepart
//             if ($existingSparepart) {
//                 $this->updateCount++;
//                 $existingSparepart->update([
//                     'unit_price_buy' => $existingSparepart['unit_price_buy'] > $row['purchase_price'] ? $existingSparepart['unit_price_buy'] : $row['purchase_price']
//                 ]);
//                 $sparepart = $existingSparepart;
//             } else {
//                 $this->successCount++;
//                 $sparepart = Sparepart::create($sparepartData);
//             }

//             // Handle single seller (seller column contains seller id)
//             if (!empty($row['seller'])) {
//                 // Find or create seller by id
//                 $seller = Seller::find($row['seller']);

//                 if (!$seller) {
//                     throw ValidationException::withMessages([
//                         'seller' => ["Seller with ID {$row['seller']} not found."],
//                     ]);
//                 }

//                 DetailSparepart::updateOrCreate(
//                     [
//                         'sparepart_id' => $sparepart->id,
//                         'seller_id' => $seller->id,
//                     ],
//                     [
//                         'unit_price' => $row['purchase_price'],
//                         'quantity' => 0, // Default to 0 since not provided
//                     ]
//                 );
//             }
//         });

//         return $sparepart;
//     }

//     /**
//      * @return array
//      */
//     public function rules(): array
//     {
//         // Return empty rules to disable default validation
//         return [];
//     }

//     public function getSuccessCount()
//     {
//         return $this->successCount;
//     }

//     public function getUpdateCount()
//     {
//         return $this->updateCount;
//     }
// }

class SparepartImport implements ToCollection, WithHeadingRow, WithChunkReading
{

    private $newCount = 0;
    private $updateCount = 0;

    private const BRANCH_MAP = [
        'SMG' => 'Semarang',
        'JKT' => 'Jakarta'
    ];

    public function collection(Collection $rows)
    {
        ini_set('max_execution_time', 0);

        $sparepartData = [];
        $detailSparepartData = [];
        $uniqueKeys = [];
        $branchStockData = [];

        foreach ($rows as $index => $row) {
            Log::info("Processing row " . ($index + 1) . ": " . json_encode($row));

            // Skip empty rows
            if (empty($row['sparepart_number']) && empty($row['sparepart_name']) && empty($row['purchase_price'])) {
                continue;
            }

            if ($row['sparepart_name'] === 0) continue;

            // Validate each row
            $validator = Validator::make([
                'sparepart_number' => $row['sparepart_number'],
                'sparepart_name'  => $row['sparepart_name'],
                'purchase_price'   => $row['purchase_price'],
                'seller'   => $row['seller'] ?? null,
                'branch'   => $row['branch'] ?? null,
                'quantity' => $row['quantity'] ?? null,
            ], [
                'sparepart_number' => 'required|string',
                'sparepart_name' => 'nullable|string',
                'purchase_price' => 'required|numeric|min:0',
                'seller' => 'nullable',
                'branch' => 'nullable',
                'quantity' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                Log::error("Validation failed for row " . ($index + 1) . ": " . json_encode($row) . " Errors: " . json_encode($validator->errors()->toArray()));
                throw ValidationException::withMessages($validator->errors()->toArray());
            }

            $sparepartData[] = [
                'slug' => Str::slug($row['sparepart_number']),
                'sparepart_number' => $row['sparepart_number'],
                'sparepart_name' => $row['sparepart_name'] ?? 'UNDEFINED',
                'unit_price_buy' => $row['purchase_price'],
                'unit_price_sell' => $row['purchase_price'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $branchStockData[] = [
                'sparepart_number' => $row['sparepart_number'],
                'branch' => $row['branch'] ?? null,
                'quantity' => $row['quantity'] ?? 0,
            ];

            if (!empty($row['seller'])) {
                $detailSparepartData[] = [
                    'sparepart_number' => $row['sparepart_number'],
                    'seller_id' => $row['seller'],
                    'unit_price' => $row['purchase_price'],
                    'quantity' => $row['quantity'] ?? 0
                ];
            }

            $uniqueKeys[] = $row['sparepart_number'];
        }

        DB::transaction(function () use ($sparepartData, $detailSparepartData, $uniqueKeys, $branchStockData) {
             // Fetch existing ones to detect new vs updated
            $existingPrices = Sparepart::whereIn('sparepart_number', $uniqueKeys)
                ->pluck('unit_price_buy', 'sparepart_number')
                ->toArray();

            $this->updateCount += count($existingPrices);
            $this->newCount += count($uniqueKeys) - count($existingPrices);

            $maxSparepartData = array_map(function ($data) use ($existingPrices) {
                $part = $data['sparepart_number'] ?? null;
                if ($part && isset($existingPrices[$part])) {
                    $data['unit_price_buy'] = max($existingPrices[$part], $data['unit_price_buy']);
                    // optionally update unit_price_sell too or other fields
                }
                return $data;
            }, $sparepartData);

            // Upsert the sparepart
            Sparepart::upsert(
                $maxSparepartData,
                ['sparepart_number'], // unique key to check for existing record
                ['unit_price_buy', 'unit_price_sell'],    // columns to update if record exists
            );
            $spareparts = Sparepart::whereIn('sparepart_number', array_column($sparepartData, 'sparepart_number'))
                ->get()
                ->keyBy('sparepart_number');

            $stockService = app(SparepartStockService::class);
            $allBranches = Branch::all();
            // Handle detail spareparts if any exist
            if (!empty($detailSparepartData)) {
                // Prepare detail sparepart records with actual sparepart_ids
                $detailRecords = array_map(function ($detail) use ($spareparts) {
                    $sparepart = $spareparts[$detail['sparepart_number']] ?? null;
                    if (!$sparepart) return null;

                    return [
                        'sparepart_id' => $sparepart->id,
                        'seller_id' => $detail['seller_id'],
                        'unit_price' => $detail['unit_price'],
                        'quantity' => $detail['quantity'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $detailSparepartData);

                // Filter out any null records
                $detailRecords = array_filter($detailRecords);

                // Bulk upsert detail spareparts
                if (!empty($detailRecords)) {
                    DetailSparepart::upsert(
                        $detailRecords,
                        ['sparepart_id', 'seller_id'],
                        ['unit_price']
                    );
                }
            }

            if (!empty($branchStockData)) {
                foreach ($branchStockData as $stockInfo) {
                    $sparepart = $spareparts[$stockInfo['sparepart_number']] ?? null;

                    if (!$sparepart) {
                        continue;
                    }

                    $branchModel = $this->resolveBranchModel($stockInfo['branch']) ?? $this->resolveBranchModel('Semarang');

                    if ($branchModel) {
                        $record = $stockService->ensureStockRecord($sparepart, $branchModel->id, true);
                        $oldQuantity = (int) $record->quantity;
                        $newQuantity = max(0, (int) $stockInfo['quantity']);
                        $record->quantity = $newQuantity;
                        $record->save();

                        // Import RESETS stock to an absolute value; log the net change. No user is in
                        // scope for an import, so employee_id is null.
                        $delta = $newQuantity - $oldQuantity;
                        if ($delta !== 0) {
                            $stockService->logMovement(
                                $sparepart,
                                $record->branch_id,
                                $delta,
                                'Import',
                                $sparepart->id,
                                null,
                                'Excel import'
                            );
                        }
                    }

                    foreach ($allBranches as $branch) {
                        $stockService->ensureStockRecord($sparepart, $branch->id);
                    }
                }
            }
        });
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        // Return empty rules to disable default validation
        return [];
    }

    protected function resolveBranchModel(?string $value): ?Branch
    {
        if (!$value) {
            return Branch::query()
                ->where('code', 'SMG')
                ->orWhere('name', 'Semarang')
                ->first();
        }

        $normalized = strtolower($value);

        return Branch::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->orWhereRaw('LOWER(code) = ?', [$normalized])
            ->first();
    }

    public function getNewCount()
    {
        return $this->newCount;
    }

    public function getUpdateCount()
    {
        return $this->updateCount;
    }
}
