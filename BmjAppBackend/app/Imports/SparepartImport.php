<?php

namespace App\Imports;

use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SparepartImport implements ToModel, WithHeadingRow
{
    private $successCount = 0;
    private $updateCount = 0;

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Check if the row is empty (all relevant fields are null or empty)
        $relevantFields = ['part_number', 'sparepart_name', 'purchase_price', 'seller'];
        $isEmpty = true;
        foreach ($relevantFields as $field) {
            if (!empty($row[$field])) {
                $isEmpty = false;
                break;
            }
        }

        if ($isEmpty) {
            Log::info("Skipping empty row: " . json_encode($row));
            return null; // Skip empty rows
        }

        // Validate the row manually
        $validator = Validator::make($row, [
            'part_number' => 'required|string',
            'sparepart_name' => 'required|string',
            'purchase_price' => 'nullable|numeric|min:0',
            'seller' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::warning("Skipping invalid row: " . json_encode($row) . " Errors: " . json_encode($validator->errors()->all()));
            return null; // Skip invalid rows
        }

        // Find existing sparepart by part_number (mapped to sparepart_number)
        $existingSparepart = Sparepart::where('sparepart_number', $row['part_number'])
            ->first();

        // Prepare sparepart data
        $sparepartData = [
            'slug' => Str::slug($row['part_number'] . '-' . $row['sparepart_name']),
            'sparepart_number' => $row['part_number'],
            'sparepart_name' => $row['sparepart_name'],
            'unit_price_sell' => isset($row['purchase_price']) ? $row['purchase_price'] : null,
            'total_unit' => 0, // Default to 0 since not provided
        ];

        // Create or update sparepart
        $sparepart = null;
        if ($existingSparepart) {
            $this->updateCount++;
            $sparepart = new Sparepart($sparepartData);
        } else {
            $this->successCount++;
            $sparepart = new Sparepart($sparepartData);
        }

        // Save the sparepart and handle detail spareparts
        DB::transaction(function () use ($sparepart, $row) {
            $sparepart->save();

            // Handle single seller (seller column contains seller name)
            if (!empty($row['seller'])) {
                // Find or create seller by name
                $seller = Seller::firstOrCreate(
                    ['name' => $row['seller']],
                    ['type' => 'Supplier'] // Default type for new sellers
                );

                // Create or update DetailSparepart
                DetailSparepart::updateOrCreate(
                    [
                        'sparepart_id' => $sparepart->id,
                        'seller_id' => $seller->id,
                    ],
                    [
                        'unit_price' => isset($row['purchase_price']) ? $row['purchase_price'] : 0,
                        'quantity' => 0, // Default to 0 since not provided
                    ]
                );
            }
        });

        return $sparepart;
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        // Return empty rules to disable default validation
        return [];
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getUpdateCount()
    {
        return $this->updateCount;
    }
}
