<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WabaChannel;
use App\Http\Requests\ListTenantsRequest;
use App\Http\Requests\ListTenantChannelsRequest;

class AdminController extends Controller
{
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
}
