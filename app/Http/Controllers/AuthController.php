<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Register a new user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        try {
            // Normalize early so validation/uniqueness use a consistent format
            $normalizedPhone = $this->normalizePhoneNumber($request->phone ?? '');

            // Validate registration data
            $validator = Validator::make(array_merge($request->all(), [
                'phone' => $normalizedPhone,
            ]), [
                'name' => 'required|string|max:255',
                'phone' => ['required', 'string', 'unique:users,phone', 'regex:/^(\+254|0)[7-9]\d{8}$/'],
                'password' => 'required|string|min:8|confirmed',
                'email' => 'nullable|email|unique:users,email',
                'county' => 'nullable|string|max:100',
            ], [
                'phone.regex' => 'Please provide a valid Kenyan phone number (e.g., +254712345678 or 0712345678)',
                'phone.unique' => 'This phone number is already registered. Please login instead.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if phone already exists (additional check)
            if (User::phoneExists($normalizedPhone)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number already registered. Please login instead.',
                    'suggest_login' => true
                ], 409);
            }

            // Create user
            $user = User::create([
                'name' => $request->name,
                'phone' => $normalizedPhone,
                'email' => $request->email,
                'county' => $request->county,
                'password' => Hash::make($request->password),
            ]);

            // Generate Sanctum token
            $token = $user->createToken('farmOS-app')->plainTextToken;

            // Update last login
            $user->updateLastLogin();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'county' => $user->county,
                        'language' => $user->language,
                        'timezone' => $user->timezone,
                    ],
                    'token' => $token,
                    'farms' => [] // New user has no farms yet
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Lightweight phone availability check used by the frontend during registration.
     */
    public function checkPhone(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        // Normalize incoming phone to align with storage format.
        $phone = $this->normalizePhoneNumber($request->query('phone', $request->phone));

        $exists = User::phoneExists($phone);

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => $exists,
                'phone' => $phone,
            ],
        ]);
    }

    /**
     * Login user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        try {
            // Validate login data
            $validator = Validator::make($request->all(), [
                'phone' => 'required|string',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Normalize phone number
            $phone = $this->normalizePhoneNumber($request->phone);

            // Find user by phone
            $user = User::findByPhone($phone);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials. Please check your phone number and password.'
                ], 401);
            }

            // Check if user is active
            if ($user->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact support.'
                ], 403);
            }

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials. Please check your phone number and password.'
                ], 401);
            }

            // Revoke all existing tokens for security
            $user->tokens()->delete();

            // Generate new Sanctum token
            $token = $user->createToken('farmOS-app')->plainTextToken;

            // Update last login
            $user->updateLastLogin();

            // Get user's farms
            $farms = $user->allFarms()->map(function ($farm) use ($user) {
                return [
                    'id' => $farm->id,
                    'name' => $farm->name,
                    'location' => $farm->location,
                    'size' => $farm->size,
                    'size_unit' => $farm->size_unit,
                    'type' => $farm->type,
                    'status' => $farm->status,
                    'role' => $user->getRoleOnFarm($farm->id),
                    'tenant_schema' => $farm->tenant_schema_name,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'county' => $user->county,
                        'language' => $user->language,
                        'timezone' => $user->timezone,
                    ],
                    'token' => $token,
                    'farms' => $farms
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Logout user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get authenticated user info
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get user's farms with role information
            $farms = $user->allFarms()->map(function ($farm) use ($user) {
                return [
                    'id' => $farm->id,
                    'name' => $farm->name,
                    'location' => $farm->location,
                    'size' => $farm->size,
                    'size_unit' => $farm->size_unit,
                    'type' => $farm->type,
                    'status' => $farm->status,
                    'role' => $user->getRoleOnFarm($farm->id),
                    'permissions' => $user->getPermissionsOnFarm($farm->id),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'county' => $user->county,
                        'language' => $user->language,
                        'timezone' => $user->timezone,
                        'status' => $user->status,
                        'last_login_at' => $user->last_login_at,
                    ],
                    'farms' => $farms
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user information',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Refresh authentication token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            // Generate new token
            $token = $user->createToken('farmOS-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Refresh authentication token without requiring authentication
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function refreshToken(Request $request): JsonResponse
    {
        try {
            // Get token from Authorization header
            $authHeader = $request->header('Authorization', '');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
            }

            $token = substr($authHeader, 7); // Remove "Bearer " prefix
            
            // Find the token record
            $personalAccessToken = PersonalAccessToken::findToken($token);
            
            if (!$personalAccessToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token'
                ], 401);
            }

            $user = $personalAccessToken->tokenable;
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token user not found'
                ], 401);
            }

            // Delete the old token
            $personalAccessToken->delete();

            // Generate new token
            $newToken = $user->createToken('farmOS-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $newToken
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 401);
        }
    }

    /**
     * Update user profile
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|nullable|email|unique:users,email,' . $user->id,
                'county' => 'sometimes|nullable|string|max:100',
                'language' => 'sometimes|string|in:en,sw',
                'timezone' => 'sometimes|string',
                'preferences' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                        'county' => $user->county,
                        'language' => $user->language,
                        'timezone' => $user->timezone,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Profile update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Normalize phone number to consistent format
     * 
     * @param string $phone
     * @return string
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove spaces, dashes, etc.
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        
        // Convert 0712345678 to +254712345678
        if (preg_match('/^0([7-9]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        }
        
        // Ensure +254 format
        if (preg_match('/^254([7-9]\d{8})$/', $phone, $matches)) {
            return '+254' . $matches[1];
        }
        
        return $phone;
    }
}
