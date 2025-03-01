<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DetailAccesses;
use Illuminate\Http\Request;
use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index() {
        return Employee::all();
    }

    public function show($id) {
        $employee = Employee::find($id);
        $response = [
            'message' => 'Employee data',
            'data' => $employee
        ];

        return response()->json($response);
    }

    public function store(Request $request) {
        return Employee::create($request->all());
    }

    public function update(Request $request, $id) {
        $employee = Employee::find($id);
        $employee->update($request->all());
        return $employee;
    }

    public function destroy($id) {
        return Employee::destroy($id);
    }

    // Extra function
    public function getAll()
    {
        $employees = Employee::paginate(20);

        $response = [
            'message' => 'List all employees',
            'data' => $employees
        ];

        return response()->json($response);
    }

    public function getEmployeeAccess($id)
    {
        // Find the employee
        $employee = $this->show($id);

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        // Get the employee's access list
        $accesses = DetailAccesses::where('id_employee', $id)
            ->with('accesses')
            ->get()
            ->pluck('accesses.access')
            ->toArray();

        // Map the role to the path and name
        $roleMapping = [
            'Director' => ['path' => '/director', 'name' => 'Director'],
            'Marketing' => ['path' => '/marketing', 'name' => 'Marketing'],
            'Inventory' => ['path' => '/inventory', 'name' => 'Inventory'],
            'Finance' => ['path' => '/finance', 'name' => 'Finance'],
            'Service' => ['path' => '/service', 'name' => 'Service'],
        ];

        // Get the role-specific data
        $roleData = $roleMapping[$employee->role] ?? null;

        if (!$roleData) {
            return response()->json(['error' => 'Role not found'], 404);
        }

        // Format the response
        $response = [
            'path' => $roleData['path'],
            'name' => $roleData['name'],
            'feature' => $accesses,
        ];

        return response()->json($response);
    }
}
