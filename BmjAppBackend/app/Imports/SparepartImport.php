<?php

namespace App\Imports;

use App\Models\Sparepart;
use App\Models\DetailSparepart;
use App\Models\Seller;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;

class SparepartImport implements ToModel, WithHeadingRow, WithValidation, WithEvents
{
    private $successCount = 0;
    private $updateCount = 0;
    private $sellerHeaders = [];

    /**
     * Register events to capture headers before import.
     *
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                $worksheet = $event->reader->getActiveSheet();
                $highestColumn = $worksheet->getHighestColumn();
                $headers = $worksheet->rangeToArray("A1:{$highestColumn}1")[0];

                // Log headers for debugging
                \Log::info('Excel Headers: ' . json_encode($headers));

                $this->sellerHeaders = array_filter($headers, function ($header) {
                    return Str::startsWith($header, 'seller_') && (
                        Str::endsWith($header, '_name') ||
                        Str::endsWith($header, '_price') ||
                        Str::endsWith($header, '_quantity')
                    );
                });
            },
        ];
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function model(array $row)
    {
        // Find existing sparepart by sparepart_number
        $existingSparepart = Sparepart::where('sparepart_number', $row['sparepart_number'])
            ->orderByDesc('version')
            ->first();

        // Prepare sparepart data
        $sparepartData = [
            'slug' => Str::slug($row['sparepart_number'] . '-' . $row['sparepart_name']),
            'sparepart_number' => $row['sparepart_number'],
            'sparepart_name' => $row['sparepart_name'],
            'unit_price_sell' => $row['unit_price_sell'],
            'total_unit' => $row['total_unit'],
            'version' => $existingSparepart ? $existingSparepart->version + 1 : 1,
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

            // Group seller headers by their index (e.g., seller_1_name, seller_1_price, seller_1_quantity)
            $sellerGroups = [];
            foreach ($this->sellerHeaders as $header) {
                if (preg_match('/seller_(\d+)_(\w+)/', $header, $matches)) {
                    $index = $matches[1];
                    $type = $matches[2];
                    $sellerGroups[$index][$type] = $header;
                }
            }

            // Process each seller group
            foreach ($sellerGroups as $index => $fields) {
                $nameKey = $fields['name'] ?? null;
                $priceKey = $fields['price'] ?? null;
                $quantityKey = $fields['quantity'] ?? null;

                // Check if all required seller fields exist and are non-empty
                if ($nameKey && $priceKey && $quantityKey && !empty($row[$nameKey]) && isset($row[$priceKey]) && isset($row[$quantityKey])) {
                    // Find or create seller by name
                    $seller = Seller::firstOrCreate(
                        ['name' => $row[$nameKey]],
                        ['type' => 'Supplier'] // Default type for new sellers
                    );

                    // Create or update DetailSparepart
                    DetailSparepart::updateOrCreate(
                        [
                            'sparepart_id' => $sparepart->id,
                            'seller_id' => $seller->id,
                        ],
                        [
                            'unit_price' => $row[$priceKey],
                            'quantity' => $row[$quantityKey],
                        ]
                    );
                }
            }
        });

        return $sparepart;
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        // Core sparepart validation rules
        $rules = [
            'sparepart_number' => 'required|string',
            'sparepart_name' => 'required|string',
            'unit_price_sell' => 'required|numeric|min:0',
            'total_unit' => 'required|integer|min:0',
        ];

        // Dynamically add validation rules for seller headers
        foreach ($this->sellerHeaders as $header) {
            if (Str::endsWith($header, '_name')) {
                $rules[$header] = 'nullable|string';
            } elseif (Str::endsWith($header, '_price')) {
                $rules[$header] = 'nullable|numeric|min:0';
            } elseif (Str::endsWith($header, '_quantity')) {
                $rules[$header] = 'nullable|integer|min:0';
            }
        }

        return $rules;
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
