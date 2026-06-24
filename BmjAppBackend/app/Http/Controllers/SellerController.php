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
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function get(Request $request, $slug)
    {
        try {
            $seller = Seller::where('code', $slug)->first();

            if (!$seller) {
                return response()->json(['message' => 'Seller not found'], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Seller retrieved successfully',
                'data' => $seller
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string'
        ]);

        // Cap the slug body so a max-length code can't overflow the slug column.
        $validated['slug'] = Str::limit(Str::slug($validated['code']), 240, '') . '-' . Str::random(6);

        DB::beginTransaction();
        try {
            $seller = Seller::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Seller created successfully',
                'data' => $seller
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
            DB::rollBack();
            return response()->json([
                'message' => 'Seller creation failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $slug)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'type' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $seller = Seller::where('code', $slug)->lockForUpdate()->first();
            if (!$seller) {
                DB::rollBack();
                return response()->json(['message' => 'Seller not found'], Response::HTTP_NOT_FOUND);
            }

            $seller->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Seller updated successfully',
                'data' => $seller
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
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
            $seller = Seller::where('code', $slug)->lockForUpdate()->first();
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
                'message' => 'Seller deleted successfully',
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
            DB::rollBack();
            return response()->json([
                'message' => 'Seller deletion failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
