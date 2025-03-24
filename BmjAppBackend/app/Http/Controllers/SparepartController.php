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
    }

    // Extra function
    public function getAll(Request $request)
    {
        try {
            $q = $request->query('q');
            $spareparts = $this->getAccessedSparepart($request);
            // Build the query with search functionality
            $sparepartsQuery = $spareparts->where(function ($query) use ($q) {
                    $query->where('name', 'like', "%$q%")
                        ->orWhere('no_sparepart', 'like', "%$q%");
                });

            // Paginate the results
            $paginatedSpareparts = $sparepartsQuery->paginate(20);

            // Return the response with transformed data and pagination details
            return response()->json([
                'message' => 'List of all spareparts retrieved successfully',
                'data' => [
                    'items' => $paginatedSpareparts
                ],
            ], Response::HTTP_OK);

        } catch (\Throwable $th) {
            return $this->handleError($th);
        }
    }

    public function getDetail(Request $request, $slug)
    {
        try {
            $spareparts = $this->getAccessedSparepart($request);
            $sparepart = $spareparts->where('slug', $slug)->first();

            if (!$sparepart) {
                return $this->handleNotFound('Sparepart not found');
            }

            return response()->json([
                'message' => 'Sparepart details retrieved successfully',
                'data' => $sparepart
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

            // Return the response with transformed data and pagination details
            return $spareparts;

        } catch (\Throwable $th) {
            echo('Error at getAccessedSparepart: ' . $th->getMessage());
            return [];
        }
    }

    // Helper methods for consistent error handling
    protected function handleError(\Throwable $th, $message = 'Internal server error')
    {
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
