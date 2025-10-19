<?php

namespace App\Imports;

use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
// use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
// use Maatwebsite\Excel\Concerns\WithHeadingRow;
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

class SparepartImport implements ToCollection, WithChunkReading
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

        foreach ($rows as $index => $row) {
            Log::info("Processing row " . ($index + 1) . ": " . json_encode($row));
            // Skip header row if needed
            if ($index === 0) continue;

            // Skip empty rows
            if (empty($row[1]) && empty($row[2]) && empty($row[3])) {
                continue;
            }

            if($row[2] === 0) continue;

            // Validate each row
            $validator = Validator::make([
                'sparepart_number' => $row[1],
                'sparepart_name'  => $row[2],
                'purchase_price'   => $row[3],
                'seller'   => $row[4] ?? null,
                'branch'   => $row[5] ?? null,
            ], [
                'sparepart_number' => 'required|string',
                'sparepart_name' => 'required|string',
                'purchase_price' => 'required|numeric|min:0',
                'seller' => 'nullable',
                'branch' => 'nullable',
            ]);

            if ($validator->fails()) {
                Log::error("Validation failed for row " . ($index + 1) . ": " . json_encode($row) . " Errors: " . json_encode($validator->errors()->toArray()));
                throw ValidationException::withMessages($validator->errors()->toArray());
            }

            $sparepartData[] = [
                'slug' => Str::slug($row['1']),
                'sparepart_number' => $row['1'],
                'sparepart_name' => $row['2'],
                'unit_price_buy' => $row['3'],
                'unit_price_sell' => $row['3'],
                'branch' => isset($row[5]) ? (self::BRANCH_MAP[$row[5]] ?? 'Semarang') : 'Semarang',
                'total_unit' => 0, // Default to 0 since not provided
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (!empty($row[4])) {
                $detailSparepartData[] = [
                    'sparepart_number' => $row[1], // We'll use this to link with sparepart later
                    'seller_id' => $row[4],
                    'unit_price' => $row[3],
                    'quantity' => 0
                ];
            }

            $uniqueKeys[] = $row['1'];
        }

        DB::transaction(function () use ($sparepartData, $detailSparepartData, $uniqueKeys) {
             // Fetch existing ones to detect new vs updated
            $existing = Sparepart::whereIn('sparepart_number', $uniqueKeys)
                ->pluck('sparepart_number')
                ->toArray();

            $this->updateCount += count($existing);
            $this->newCount += count($uniqueKeys) - count($existing);

            // Upsert the sparepart
            Sparepart::upsert(
                $sparepartData,
                ['sparepart_number'], // unique key to check for existing record
                ['unit_price_buy']    // columns to update if record exists
            );

            // Handle detail spareparts if any exist
            if (!empty($detailSparepartData)) {
                // Get all spareparts that were just upserted
                $spareparts = Sparepart::whereIn('sparepart_number', array_column($sparepartData, 'sparepart_number'))
                    ->get()
                    ->keyBy('sparepart_number');

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

      public function getNewCount()
    {
        return $this->newCount;
    }

    public function getUpdateCount()
    {
        return $this->updateCount;
    }
}
