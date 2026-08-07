<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\LoginRequest;

class AuthController extends Controller
{
    /**
     * Authenticate session cookie via login credentials.
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated.'],
            ]);
        }

        // Regenerate session to prevent session fixation attacks if web session exists
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'tenant_id' => $user->tenant_id,
            ],
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->id,
                'name' => $user->tenant->name,
                'slug' => $user->tenant->slug,
                'type' => $user->tenant->type,
            ] : null,
        ]);
    }

    /**
     * Log user out and invalidate secure session cookies.
     */
    public function logout(Request $request)
    {
        if ($request->user()?->currentAccessToken()) {
            $request->user()->currentAccessToken()->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully.'
        ]);
    }

    /**
     * Get active logged in user details.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'tenant_id' => $user->tenant_id,
            'tenant' => $user->tenant,
            'fcm_tokens_count' => $user->fcmTokens()->count(),
        ]);
    }

    /**
     * Register or update a user FCM token for mobile push notifications.
     */
    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
            'device_type' => 'nullable|string',
        ]);

        $user = $request->user();

        $tokenRecord = \App\Models\UserFcmToken::updateOrCreate(
            [
                'user_id' => $user->id,
                'fcm_token' => $request->fcm_token,
            ],
            [
                'device_type' => $request->device_type ?? 'mobile',
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'FCM token registered successfully.',
            'fcm_token' => $tokenRecord->fcm_token,
            'device_type' => $tokenRecord->device_type,
        ]);
    }

    /**
     * Remove an FCM token when a user logs out from a device.
     */
    public function removeFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $user = $request->user();
        $user->fcmTokens()->where('fcm_token', $request->fcm_token)->delete();

        return response()->json([
            'message' => 'FCM token removed successfully.'
        ]);
    }
}
