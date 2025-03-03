<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * index
     *
     * @param  mixed $request
     * @return void
     */
    public function index(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $employee= Employee::where('email', $request->email)->first();

            if (!$employee || !Hash::check($request->password, $employee->password)) {
                return response([
                    'success'   => false,
                    'message' => ['These credentials do not match our records.']
                ], 404);
            }

            $token = $employee->createToken('ApiToken')->plainTextToken;

            $response = [
                'success'   => true,
                'Employee'      => $employee,
                'token'     => $token
            ];

        return response($response, 201);
    }

    /**
     * logout
     *
     * @return void
     */
    public function logout()
    {
        Auth::logout();
        return response()->json([
            'success'    => true
        ], 200);
    }

}
