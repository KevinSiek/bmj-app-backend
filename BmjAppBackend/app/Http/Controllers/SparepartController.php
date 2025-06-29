<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sparepart;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;

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
        try {
            // Validate file upload
            $validator = Validator::make($request->all(), [
                'file' => 'required|mimes:xlsx,xls,csv',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], Response::HTTP_BAD_REQUEST);
            }

            $import = new \App\Imports\SparepartImport();

            // Start transaction
            \DB::beginTransaction();

            try {
                \Excel::import($import, $request->file('file'));
                \DB::commit();

                return response()->json([
                    'message' => 'Spareparts data updated successfully',
                    'data' => [
                        'new_records' => $import->getSuccessCount(),
                        'updated_records' => $import->getUpdateCount(),
                    ],
                ], Response::HTTP_OK);
            } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                \DB::rollBack();
                return response()->json([
                    'message' => 'Validation error in Excel file',
                    'errors' => $e->failures(),
                ], Response::HTTP_BAD_REQUEST);
            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }
        } catch (\Throwable $th) {
            return $this->handleError($th, 'Error processing spareparts data');
        }
    }

    // Extra function
    public function get(Request $request, $id)
    {
        try {
            $spareparts = $this->getAccessedSparepart($request);
            $sparepart = $spareparts->with('detailSpareparts.seller')
                ->where('id', $id)
                ->firstOrFail();


            $formattedSparepart = [
                'id' => $sparepart->id ?? '',
                'slug' => $sparepart->slug ?? '',
                'sparepart_number' => $sparepart->sparepart_number ?? '',
                'sparepart_name' => $sparepart->sparepart_name ?? '',
                'total_unit' => $sparepart->total_unit,
                'unit_price_sell' => $sparepart->unit_price_sell,
                'unit_price_buy' => $sparepart->detailSpareparts->map(function ($detail) {
                    return [
                        'seller' => $detail->seller->name ?? '',
                        'price' => $detail->unit_price ?? 0,
                    ];
                })->toArray(),
            ];

            return response()->json([
                'message' => 'Sparepart retrieved successfully',
                'data' => $formattedSparepart,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }


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

            $paginatedSpareparts = $sparepartsQuery->paginate(20)->through(function ($data) {
                return [
                    'id' => $data->id ?? '',
                    'slug' => $data->slug ?? '',
                    'sparepart_number' => $data->sparepart_number ?? '',
                    'sparepart_name' => $data->sparepart_name ?? '',
                    'total_unit' => $data->total_unit,
                    'unit_price_sell' => $data->unit_price_sell,
                    'unit_price_buy' => $data->detailSpareparts->map(function ($detail) {
                        return [
                            'seller' => $detail->seller->name ?? '',
                            'price' => $detail->unit_price ?? 0,
                        ];
                    })->toArray(),
                ];
            });

            return response()->json([
                'message' => 'List of all spareparts retrieved successfully',
                'data' => $paginatedSpareparts,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    // Function to get spareparts by access role level
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
