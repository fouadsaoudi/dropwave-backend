<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenants.{tenantId}', function ($user, $tenantId) {
    \Illuminate\Support\Facades\Log::info("Websocket Auth attempt", [
        'user_id' => $user->id,
        'user_tenant_id' => $user->tenant_id,
        'requested_tenant_id' => $tenantId
    ]);
    return (int) $user->tenant_id === (int) $tenantId;
});
