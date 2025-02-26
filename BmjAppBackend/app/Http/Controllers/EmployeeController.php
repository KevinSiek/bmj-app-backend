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
        return Employee::find($id);
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
        $employees = Employee::all()->map(function ($employee) {
            return [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'type' => $employee->role, // Assuming "role" is equivalent to "type"
            ];
        });

        return response()->json($employees);
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
