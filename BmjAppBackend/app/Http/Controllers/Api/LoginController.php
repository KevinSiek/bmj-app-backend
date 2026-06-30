<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Validation\Rules\Password;

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
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $isAuth = Auth::guard('employee')->attempt($credentials);

            if ($isAuth) {
                $user = Auth::guard('employee')->user();
                $user->load('branch');
                $isUseTempPassword = false;
                if ($user->temp_password) {
                    $isExpired = $user->temp_pass_expires_at && \Carbon\Carbon::parse($user->temp_pass_expires_at)->isPast();
                    if (!$isExpired && $request->password == $user->temp_password) {
                        $isUseTempPassword = true;
                    }
                }
                if ($isUseTempPassword) {
                    // Use temp password to login for first time
                    $tempAlreadyUse = $user->temp_pass_already_use;
                    if ($tempAlreadyUse) {
                        // Temp password already used, prevent login with it again
                        return response()->json([
                            'message' => 'This temporary password already use, please contact the admin.'
                        ], Response::HTTP_BAD_REQUEST);
                    }
                    $userId = $user->id;
                    $userDb = Employee::find($userId);
                    // Mark as used and remove the temporary password so it cannot be reused
                    $userDb->temp_pass_already_use = true;
                    $userDb->temp_password = null;
                    $userDb->save();
                }
                $token = $user->createToken('auth_token')->plainTextToken;
                $response = [
                    'status' => true,
                    'use_temp_password' => $isUseTempPassword,
                    'message' => 'Login successful',
                    'user' => $user,
                    'access_token' => $token,
                    'token_type' => 'Bearer'
                ];

                return response()->json($response, Response::HTTP_OK);
            } else {
                $response = [
                    'status' => false,
                    'message' => 'Login failed',
                ];
                return response()->json($response, Response::HTTP_UNAUTHORIZED);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * logout
     *
     * @return void
     */
    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return response()->json([
                'success'    => true,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    public function getCurrentUser(Request $request)
    {
        try {
            $user = $request->user();
            $user->load('branch');
            return response()->json([
                'user'    => $user,
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            return response()->json([
                'message' => 'Internal server error',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function changePasswordOrPhone(Request $request)
    {
        try {
            $user = $request->user();
            $userId = $user->id;
            $employee = Employee::find($userId);

            if (!$employee) {
                return response()->json([
                    'message' => 'Employee not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Validate the request data
            $validatedData = $request->validate([
                'password' => [
                    'required',
                    'string',
                    'max:64',
                    Password::min(6)
                        ->mixedCase() // Requires at least one uppercase and one lowercase letter.
                        ->numbers(),   // Requires at least one number.
                ],
                'confirm_password' => 'required|string|same:password',
                'phone' => 'nullable|string|max:20',
            ]);

            $validatedData['password'] = bcrypt($request->password);
            if (isset($validatedData['phone'])) {
                $employee->phone = $validatedData['phone'];
            }
            $employee->update($validatedData);

            // Clear any temporary password and lift the force-change gate.
            $employee->temp_password = null;
            $employee->temp_pass_already_use = true;
            $employee->must_change_password = false;
            $employee->save();

            return response()->json([
                'message' => 'Change password success',
            ], Response::HTTP_OK);
        } catch (\Throwable $th) {
            // Let validation/not-found/http exceptions surface with their real status
            // (e.g. a weak password must be 422, not a generic 500).
            if ($th instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $th instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $th instanceof \Illuminate\Validation\ValidationException) {
                throw $th;
            }
            return response()->json([
                'message' => 'Fail to change password',
                'error' => $th->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
