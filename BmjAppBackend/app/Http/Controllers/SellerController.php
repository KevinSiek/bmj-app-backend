<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class SellerController extends Controller
{
    public function getAll(Request $request)
    {
        try {
            $q = $request->query('search');
            $query = Seller::query();

            if ($q) {
                $query->where('name', 'like', "%$q%");
            }

            $sellers = $query->paginate(20);

            return response()->json([
                'message' => 'Sellers retrieved successfully',
                'data' => $sellers
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function get(Request $request, $slug)
    {
        try {
            $seller = Seller::where('slug', $slug)->first();

            if (!$seller) {
                return response()->json(['message' => 'Seller not found'], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Seller retrieved successfully',
                'data' => $seller
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        DB::beginTransaction();
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:255',
                'name' => 'required|string|max:255',
                'type' => 'nullable|string'
            ]);

            $validated['slug'] = Str::slug($validated['code']) . '-' . Str::random(6);

            $seller = Seller::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Seller created successfully',
                'data' => $seller
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Seller creation failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $slug)
    {
        DB::beginTransaction();
        try {
            $seller = Seller::where('slug', $slug)->lockForUpdate()->first();
            if (!$seller) {
                DB::rollBack();
                return response()->json(['message' => 'Seller not found'], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'code' => 'required|string|max:255',
                'name' => 'required|string|max:255',
                'type' => 'nullable|string'
            ]);

            $seller->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Seller updated successfully',
                'data' => $seller
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Seller update failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($slug)
    {
        DB::beginTransaction();
        try {
            $seller = Seller::where('slug', $slug)->lockForUpdate()->first();
            if (!$seller) {
                DB::rollBack();
                return response()->json(['message' => 'Seller not found'], Response::HTTP_NOT_FOUND);
            }

            $deleted = $seller->delete();

            if (!$deleted) {
                DB::rollBack();
                return response()->json(['message' => 'Seller could not be deleted'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            return response()->json([
                'message' => 'Customer deleted successfully',
                'data' => $deleted
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Customer deletion failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
