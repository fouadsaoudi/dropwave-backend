<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WabaChannel;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\ListTenantsRequest;
use App\Http\Requests\ListTenantChannelsRequest;

class AdminController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!$request->user() || !$request->user()->isAdmin()) {
                return response()->json(['error' => 'forbidden', 'message' => 'Unauthorized admin access.'], 403);
            }
            return $next($request);
        });
    }

    /**
     * List all tenants in the system (Admin only).
     */
    public function listTenants(ListTenantsRequest $request)
    {
        $tenants = Tenant::withCount('channels')
            ->get(['id', 'name', 'slug', 'email', 'phone', 'is_active', 'created_at']);

        return response()->json($tenants);
    }

    /**
     * List channels for a specific tenant (Admin only).
     */
    public function listTenantChannels(ListTenantChannelsRequest $request, $tenantId)
    {
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Tenant not found.'
            ], 404);
        }

        $channels = WabaChannel::where('tenant_id', $tenantId)->get();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'channels' => $channels
        ]);
    }

    /**
     * List all system users with their roles and tenants (Admin only).
     */
    public function listUsers()
    {
        $users = User::with(['tenant', 'role'])
            ->get(['id', 'tenant_id', 'role_id', 'name', 'email', 'is_active', 'created_at']);

        return response()->json($users);
    }

    /**
     * Store/Create a new Tenant workspace (Admin only).
     */
    public function storeTenant(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:tenants,slug|max:255',
            'email' => 'required|email|unique:tenants,email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'email' => $request->email,
            'phone' => $request->phone,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Tenant workspace created successfully.',
            'tenant' => $tenant
        ], 201);
    }

    /**
     * Store/Create a new user under a tenant (Admin only).
     */
    public function storeUser(Request $request)
    {
        $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'password' => 'required|string|min:6',
            'role_id' => 'required|exists:roles,id',
        ]);

        $user = User::create([
            'tenant_id' => $request->tenant_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role_id' => $request->role_id,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'User account created successfully.',
            'user' => $user->load(['tenant', 'role'])
        ], 201);
    }

    /**
     * Update an existing user's details (Admin only).
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'role_id' => 'sometimes|required|exists:roles,id',
            'is_active' => 'sometimes|required|boolean',
            'password' => 'nullable|string|min:6',
        ]);

        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('role_id')) {
            $user->role_id = $request->role_id;
        }
        if ($request->has('is_active')) {
            $user->is_active = $request->is_active;
        }
        if ($request->filled('password')) {
            $user->password = bcrypt($request->password);
        }

        $user->save();

        return response()->json([
            'message' => 'User account updated successfully.',
            'user' => $user->load(['tenant', 'role'])
        ]);
    }

    /**
     * Delete/Remove a user profile (Admin only).
     */
    public function deleteUser($id)
    {
        if (auth()->id() == $id) {
            return response()->json([
                'message' => 'Deletions denied: you cannot remove your active admin session profile.'
            ], 400);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->delete();

        return response()->json(['message' => 'User account deleted successfully.']);
    }
}
