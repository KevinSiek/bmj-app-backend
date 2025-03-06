<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

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
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $isAuth = Auth::guard('employee')->attempt($credentials);

        if($isAuth){
            $user = Auth::guard('employee')->user();
            $token = $user->createToken('auth_token')->plainTextToken;
            $response = [
                'status' => true,
                'message' => 'Login successful',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer'
            ];

            return response()->json($response, Response::HTTP_OK);
        }
        else{
            $response = [
                'status' => false,
                'message' => 'Login failed',
            ];
            return response()->json($response, Response::HTTP_UNAUTHORIZED);
        }
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
