<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DetailAccesses;
use Illuminate\Http\Request;
use App\Models\Employee;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function index()
    {
        try {
            $employees = Employee::all();
            return response()->json([
                'message' => 'Employees retrieved successfully',
                'data' => $employees
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Employee data',
                'data' => $employee
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
        try {
            $employee = Employee::create($request->all());
            return response()->json([
                'message' => 'Employee created successfully',
                'data' => $employee
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Employee creation failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $employee->update($request->all());
            return response()->json([
                'message' => 'Employee updated successfully',
                'data' => $employee
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Employee update failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($id)
    {
        try {
            $deleted = Employee::destroy($id);

            if (!$deleted) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Employee deleted successfully',
                'data' => $deleted
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Employee deletion failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getAll()
    {
        try {
            $employees = Employee::paginate(20);
            return response()->json([
                'message' => 'List all employees',
                'data' => $employees
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getEmployeeAccess($id)
    {
        try {
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $accesses = DetailAccesses::where('id_employee', $id)
                ->with('accesses')
                ->get()
                ->pluck('accesses.access')
                ->toArray();

            $roleMapping = [
                'Director' => ['path' => '/director', 'name' => 'Director'],
                'Marketing' => ['path' => '/marketing', 'name' => 'Marketing'],
                'Inventory' => ['path' => '/inventory', 'name' => 'Inventory'],
                'Finance' => ['path' => '/finance', 'name' => 'Finance'],
                'Service' => ['path' => '/service', 'name' => 'Service'],
            ];

            $roleData = $roleMapping[$employee->role] ?? null;

            if (!$roleData) {
                return response()->json([
                    'message' => 'Role not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'path' => $roleData['path'],
                'name' => $roleData['name'],
                'feature' => $accesses
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function search(Request $request){
        try {
            $query = Employee::query();

            // General search across multiple fields using 'q' parameter
            if ($request->has('q')) {
                $searchTerm = $request->input('q');
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('fullname', 'like', "%{$searchTerm}%")
                    ->orWhere('email', 'like', "%{$searchTerm}%")
                    ->orWhere('role', 'like', "%{$searchTerm}%");
                });
            }

            // Specific field filters
            if ($request->has('name')) {
                $query->where('name', 'like', '%' . $request->input('name') . '%');
            }
            if ($request->has('email')) {
                $query->where('email', 'like', '%' . $request->input('email') . '%');
            }
            if ($request->has('role')) {
                $query->where('role', $request->input('role')); // Exact match for role
            }

            // Validate and apply sorting
            $allowedSortColumns = ['id', 'name', 'email', 'role', 'created_at', 'updated_at'];
            $sortBy = $request->input('sort_by', 'id');
            $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'id';

            $sortOrder = strtolower($request->input('sort_order', 'asc'));
            $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'asc';

            $query->orderBy($sortBy, $sortOrder);

            // Validate and apply pagination
            $perPage = (int)$request->input('per_page', 20);
            $perPage = max(1, min($perPage, 100)); // Limit per_page between 1 and 100

            $employees = $query->paginate($perPage);

            return response()->json([
                'message' => 'Employees retrieved successfully',
                'data' => $employees
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
