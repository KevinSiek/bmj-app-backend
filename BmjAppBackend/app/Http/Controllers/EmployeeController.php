<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DetailAccesses;
use Illuminate\Http\Request;
use App\Models\Employee;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

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

    public function show($slug)
    {
        try {
            $employee = Employee::where('slug', '=', $slug)->first();

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
            $validatedData = $request->validate([
                'fullname' => 'required|string|max:255',
                'role' => 'required|string',
                'email' => 'required|email|unique:employees,email',
                'username' => 'required|string|unique:employees,username|max:255',
                // 'password' => 'required|string|min:10|max:64|regex:/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{10,}$/',
                'password' => 'required',
                'temp_password' => 'nullable|string|min:10|max:64|regex:/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{10,}$/',
                'temp_pass_already_use' => 'nullable|boolean',
            ]);

            $slug = Str::slug($validatedData['fullname']);
            $validatedData['slug'] = $slug . '-' . Str::random(6); // Add randomness for uniqueness

            $employee = Employee::create($validatedData);
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

    public function update(Request $request, $slug)
    {
        try {
            // Validate the request data
            $validatedData = $request->validate([
                'fullname' => 'sometimes|string|max:255',
                'role' => 'sometimes|string',
                'email' => 'sometimes|email|unique:employees,email,' . $slug . ',slug',
                'username' => 'sometimes|string|unique:employees,username,' . $slug . ',slug|max:255',
            ]);

            // Find the employee by slug
            $employee = Employee::where('slug', $slug)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Update only the provided fields
            $employee->update($validatedData);

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

    public function destroy($slug)
    {
        try {
            $employee = Employee::where('slug','=',$slug)->first();
            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $deleted = $employee->delete();

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

    public function getAll(Request $request)
    {
        try {
            $q = $request->query('q');
            $employees = Employee::paginate(20);
            if($q){
                $searchTerm = $q;
                $employeeData = Employee::where('fullname', 'like', "%$searchTerm%");
                $employees = $employeeData->paginate(20);
            }
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
}
