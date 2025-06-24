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
    public function updateAllData(Request $request) {}

    // Extra function
    public function get(Request $request, $id)
    {
        try {
            $spareparts = $this->getAccessedSparepart($request);
            $sparepart = $spareparts->with('detailSpareparts.seller')->findOrFail($id);

            $formattedSparepart = [
                'id' => $sparepart->id ?? '',
                'slug' => $sparepart->slug ?? '',
                'sparepart_number' => $sparepart->sparepart_number ?? '',
                'sparepart_name' => $sparepart->sparepart_name ?? '',
                'totalUnit' => $sparepart->total_unit,
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

            // Build the query with search functionality
            $sparepartsQuery = $spareparts->where(function ($query) use ($q) {
                $query->where('sparepart_name', 'like', "%$q%")
                    ->orWhere('sparepart_number', 'like', "%$q%");
            })->with('detailSpareparts.seller'); // Eager load detailSpareparts for unitPriceBuy

            // Paginate the results and transform to match API contract
            $paginatedSpareparts = $sparepartsQuery->paginate(20)->through(function ($data) {
                return [
                    'id' => $data->id ?? '',
                    'slug' => $data->slug ?? '',
                    'sparepart_number' => $data->sparepart_number ?? '',
                    'sparepart_name' => $data->sparepart_name ?? '',
                    'totalUnit' => $data->total_unit,
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
