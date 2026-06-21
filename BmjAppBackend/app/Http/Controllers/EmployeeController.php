<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DetailAccesses;
use App\Models\Group;
use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    // Branch or city source value
    const SEMARANG = "Semarang";
    const JAKARTA = "Jakarta";

    const MARKETING = 'Marketing';
    const DIRECTOR = 'Director';
    const FINANCE = 'Finance';
    const SERVICE = 'Service';
    const INVENTORY_ADMIN = 'Inventory Admin';
    const INVENTORY_PURCHASE = 'Inventory Purchase';


    public function index()
    {
        try {
            $employees = Employee::with(['branch', 'group'])->get()->map(fn($e) => $this->formatEmployee($e));
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
        $validatedData = $request->validate([
            'fullname' => 'required|string|max:255',
            'role' => 'required|string',
            'branch' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email',
            'username' => 'required|string|unique:employees,username|max:255',
            'group' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $branch = Branch::whereRaw('LOWER(name) = ?', [strtolower($validatedData['branch'])])
                ->orWhereRaw('LOWER(code) = ?', [strtolower($validatedData['branch'])])
                ->first();

            if (!$branch) {
                DB::rollBack();
                return response()->json(['message' => 'Branch not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            unset($validatedData['branch']);
            $validatedData['branch_id'] = $branch->id;

            // Resolve group: find by name or create it.
            if (!empty($validatedData['group'])) {
                $group = Group::firstOrCreate(['name' => $validatedData['group']]);
                $validatedData['group_id'] = $group->id;
            }
            unset($validatedData['group']);

            // Generate a random temporary password
            $tempPassword = Str::random(12);
            $validatedData['password'] = bcrypt($tempPassword);
            $validatedData['temp_password'] = $tempPassword;
            $validatedData['temp_pass_already_use'] = false;
            $validatedData['temp_pass_expires_at'] = now()->addDay();
            // Gate the account until the user replaces the temp password (single-use in effect).
            $validatedData['must_change_password'] = true;

            // Create a slug for the employee
            $slug = Str::slug($validatedData['fullname']);
            $validatedData['slug'] = $slug . '-' . Str::random(6);

            // Create the employee
            $employee = Employee::create($validatedData);

            DB::commit();

            // Return the response with the temporary password
            return response()->json([
                'message' => 'Employee created successfully',
                'data' => [
                    'employee' => $employee,
                    'temp_password' => $tempPassword,
                ],
            ], Response::HTTP_CREATED);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Employee creation failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, $slug)
    {
        $validatedData = $request->validate([
            'fullname' => 'required|string|max:255',
            'role' => 'required|string',
            'branch' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email,' . $slug . ',slug',
            'username' => 'required|string|unique:employees,username,' . $slug . ',slug',
            'group' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            // Find the employee by slug and lock the record for update
            $employee = Employee::where('slug', $slug)->lockForUpdate()->first();

            if (!$employee) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $branch = Branch::whereRaw('LOWER(name) = ?', [strtolower($validatedData['branch'])])
                ->orWhereRaw('LOWER(code) = ?', [strtolower($validatedData['branch'])])
                ->first();

            if (!$branch) {
                DB::rollBack();
                return response()->json(['message' => 'Branch not found'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            unset($validatedData['branch']);
            $validatedData['branch_id'] = $branch->id;

            // Resolve group: find by name or create it. Pass null to clear the group.
            if (array_key_exists('group', $validatedData)) {
                if (!empty($validatedData['group'])) {
                    $group = Group::firstOrCreate(['name' => $validatedData['group']]);
                    $validatedData['group_id'] = $group->id;
                } else {
                    $validatedData['group_id'] = null;
                }
            }
            unset($validatedData['group']);

            // Update only the provided fields
            $employee->update($validatedData);

            DB::commit();

            return response()->json([
                'message' => 'Employee updated successfully',
                'data' => $employee
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Employee update failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function resetPassword($slug)
    {
        DB::beginTransaction();
        try {
            // Find the employee by slug and lock the record for update
            $employee = Employee::where('slug', $slug)->lockForUpdate()->first();

            if (!$employee) {
                DB::rollBack();
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
                'temp_pass_expires_at' => now()->addDay(),
                'must_change_password' => true,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Reset password success',
                'data' => $employee
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Reset password failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy($slug)
    {
        DB::beginTransaction();
        try {
            // Find the employee by slug and lock for deletion
            $employee = Employee::where('slug', '=', $slug)->lockForUpdate()->first();
            if (!$employee) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $deleted = $employee->delete();

            if (!$deleted) {
                // This case is unlikely if the record was found, but it's good practice.
                DB::rollBack();
                return response()->json([
                    'message' => 'Employee could not be deleted'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            DB::commit();

            return response()->json([
                'message' => 'Employee deleted successfully',
                'data' => $deleted
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'message' => 'Employee deletion failed',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function get(Request $request, $slug)
    {
        try {
            $employee = Employee::with(['branch', 'group'])->where('slug', $slug)->first();

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'message' => 'Employees retrieved successfully',
                'data' => $this->formatEmployee($employee)
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getAll(Request $request)
    {
        try {
            $q = $request->query('search');
            $query = Employee::with(['branch', 'group']);

            if ($q) {
                $searchTerm = $q;
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('fullname', 'like', "%$searchTerm%")
                        ->orWhere('username', 'like', "%$searchTerm%");
                });
            }

            $employees = $query->paginate(20);
            $employees->getCollection()->transform(fn($e) => $this->formatEmployee($e));

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

    public function getGroups(Request $request)
    {
        try {
            $search = $request->query('search');
            $query = Group::query();

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            $groups = $query->orderBy('name')->get(['id', 'name']);

            return response()->json([
                'message' => 'Groups retrieved successfully',
                'data' => $groups,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function formatEmployee(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'slug' => $employee->slug,
            'fullname' => $employee->fullname,
            'username' => $employee->username,
            'email' => $employee->email,
            'role' => $employee->role,
            'branch' => $employee->branch?->name,
            'branch_id' => $employee->branch_id,
            'group' => $employee->group?->name,
            'group_id' => $employee->group_id,
            'created_at' => $employee->created_at,
            'updated_at' => $employee->updated_at,
        ];
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
                'Inventory Purchase' => ['path' => '/inventory-purchase', 'name' => 'Inventory Purchase'],
                'Inventory Admin' => ['path' => '/inventory-admin', 'name' => 'Inventory Admin'],
                'Head Inventory' => ['path' => '/head-inventory', 'name' => 'Head Inventory'],
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
