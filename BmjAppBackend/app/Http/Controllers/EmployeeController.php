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

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'fullname' => 'required|string|max:255',
                'role' => 'required|string',
                'email' => 'required|email|unique:employees,email',
                'username' => 'required|string|unique:employees,username|max:255',
            ]);

            // Generate a random temporary password
            $tempPassword = Str::random(12);
            $validatedData['password'] = bcrypt($tempPassword);
            $validatedData['temp_password'] = $tempPassword;
            $validatedData['temp_pass_already_use'] = false;

            // Create a slug for the employee
            $slug = Str::slug($validatedData['fullname']);
            $validatedData['slug'] = $slug . '-' . Str::random(6);

            // Create the employee
            $employee = Employee::create($validatedData);

            // Return the response with the temporary password
            return response()->json([
                'message' => 'Employee created successfully',
                'data' => [
                    'employee' => $employee,
                    'temp_password' => $tempPassword,
                ],
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
            // Find the employee by slug
            $employee = Employee::where('slug', $slug)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'fullname' => 'required|string|max:255',
                'role' => 'required|string',
                'email' => 'required|email|unique:employees,email,' . $slug . ',slug',
                'username' => 'required|string|unique:employees,username,' . $slug . ',slug',
            ]);

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

    public function resetPassword($slug)
    {
        try {
            // Find the employee by slug
            $employee = Employee::where('slug', $slug)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $tempPassword = Str::random(12);
            $encryptPassword = bcrypt($tempPassword);
            // Update only the provided fields
            $employee->update([
                'temp_password' => $tempPassword,
                'password' => $encryptPassword,
                'temp_pass_already_use' => false,
            ]);

            return response()->json([
                'message' => 'Reset password success',
                'data' => $employee
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Reset password failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($slug)
    {
        try {
            $employee = Employee::where('slug', '=', $slug)->first();
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
            $q = $request->query('search');
            $query = Employee::query();

            if ($q) {
                $searchTerm = $q;
                $query->where('fullname', 'like', "%$searchTerm%");
            }

            $employees = $query->paginate(20);

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

    public function getEmployeeAccess($slug)
    {
        try {
            $employee = Employee::where('slug', '=', $slug)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }
            $employeeId = $employee->id;
            $accesses = DetailAccesses::where('id_employee', $employeeId)
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
