<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function getAll(Request $request)
    {
        try {
            $q = $request->query('search');
            $query = Customer::query();

            if ($q) {
                $query->where('company_name', 'like', "%$q%")
                    ->orWhere('office', 'like', "%$q%")
                    ->orWhere('city', 'like', "%$q%");
            }

            $customers = $query->paginate(20);

            return response()->json([
                'message' => 'Customers retrieved successfully',
                'data' => $customers
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
            $customer = Customer::where('slug', $slug)->first();

            if (!$customer) {
                return response()->json(['message' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Customer retrieved successfully',
                'data' => $customer
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
                'company_name' => 'required|string|max:255',
                'office' => 'required|string|max:255',
                'address' => 'required|string',
                'urban' => 'required|string',
                'subdistrict' => 'required|string',
                'city' => 'required|string',
                'province' => 'required|string',
                'postal_code' => 'required|numeric',
            ]);

            $validated['slug'] = Str::slug($validated['company_name']) . '-' . Str::random(6);

            $customer = Customer::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Customer created successfully',
                'data' => $customer
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Customer creation failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $slug)
    {
        DB::beginTransaction();
        try {
            $customer = Customer::where('slug', $slug)->lockForUpdate()->first();
            if (!$customer) {
                DB::rollBack();
                return response()->json(['message' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'company_name' => 'required|string|max:255',
                'office' => 'required|string|max:255',
                'address' => 'required|string',
                'urban' => 'required|string',
                'subdistrict' => 'required|string',
                'city' => 'required|string',
                'province' => 'required|string',
                'postal_code' => 'required|numeric',
            ]);

            $customer->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Customer updated successfully',
                'data' => $customer
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Customer update failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($slug)
    {
        DB::beginTransaction();
        try {
            $customer = Customer::where('slug', $slug)->lockForUpdate()->first();
            if (!$customer) {
                DB::rollBack();
                return response()->json(['message' => 'Customer not found'], Response::HTTP_NOT_FOUND);
            }

            $deleted = $customer->delete();

            if (!$deleted) {
                DB::rollBack();
                return response()->json(['message' => 'Customer could not be deleted'], Response::HTTP_INTERNAL_SERVER_ERROR);
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
