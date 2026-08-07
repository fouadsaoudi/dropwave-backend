<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\WebhookController;

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CallController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Meta Webhooks
Route::get('/webhooks/meta', [WebhookController::class, 'verify']);
Route::post('/webhooks/meta', [WebhookController::class, 'receive'])->middleware('meta.webhook.signature');

Route::get('/media/proxy', [\App\Http\Controllers\Api\MediaController::class, 'proxy']);

// Protected routes (Sanctum authed & Tenant scoped)
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/user/fcm-token', [AuthController::class, 'updateFcmToken']);
    Route::delete('/user/fcm-token', [AuthController::class, 'removeFcmToken']);

    // Channels
    Route::get('/channels', [ChannelController::class, 'index']);
    Route::post('/channels/connect', [ChannelController::class, 'connect']);
    Route::post('/channels/manual', [ChannelController::class, 'connectManual']);
    Route::post('/channels/{id}/override-webhook', [ChannelController::class, 'overrideWebhook']);
    Route::post('/channels/{id}/toggle-calling', [ChannelController::class, 'toggleCalling']);

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/counts', [ConversationController::class, 'counts']);
    Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/claim', [ConversationController::class, 'claim']);
    Route::post('/conversations/{id}/assign', [ConversationController::class, 'assign']);
    Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
    Route::post('/conversations/{id}/reopen', [ConversationController::class, 'reopen']);
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('/conversations/{id}/request-location', [ConversationController::class, 'requestLocation']);
    Route::post('/conversations/{id}/read', [ConversationController::class, 'markAsRead']);
    Route::post('/conversations/send-template', [ConversationController::class, 'sendTemplate']);

    // Drivers
    Route::get('/drivers', [\App\Http\Controllers\Api\DriverController::class, 'index']);
    Route::post('/drivers', [\App\Http\Controllers\Api\DriverController::class, 'store']);
    Route::put('/drivers/{id}', [\App\Http\Controllers\Api\DriverController::class, 'update']);
    Route::delete('/drivers/{id}', [\App\Http\Controllers\Api\DriverController::class, 'destroy']);

    // Orders
    Route::post('/conversations/{id}/send-order', [\App\Http\Controllers\Api\OrderController::class, 'store']);
    Route::get('/orders', [\App\Http\Controllers\Api\OrderController::class, 'index']);
    Route::put('/orders/{id}', [\App\Http\Controllers\Api\OrderController::class, 'update']);




    // Calling
    Route::get('/conversations/{id}/calls', [CallController::class, 'index']);
    Route::post('/conversations/{id}/call/initiate', [CallController::class, 'initiate']);
    Route::post('/conversations/{id}/call/accept', [CallController::class, 'accept']);
    Route::post('/conversations/{id}/call/terminate', [CallController::class, 'terminate']);
    Route::post('/conversations/{id}/call/reject', [CallController::class, 'reject']);

    // Contacts
    Route::get('/contacts', [\App\Http\Controllers\Api\ContactController::class, 'index']);
    Route::post('/contacts', [\App\Http\Controllers\Api\ContactController::class, 'store']);
    Route::put('/contacts/{id}', [\App\Http\Controllers\Api\ContactController::class, 'update']);
    Route::delete('/contacts/{id}', [\App\Http\Controllers\Api\ContactController::class, 'destroy']);
    Route::post('/contacts/import', [\App\Http\Controllers\Api\ContactController::class, 'import']);
    Route::get('/contacts/{contactId}/addresses', [\App\Http\Controllers\Api\AddressController::class, 'index']);
    Route::post('/contacts/{contactId}/addresses', [\App\Http\Controllers\Api\AddressController::class, 'store']);
    Route::delete('/addresses/{id}', [\App\Http\Controllers\Api\AddressController::class, 'destroy']);

    // Templates
    Route::get('/templates', [\App\Http\Controllers\Api\TemplateController::class, 'index']);
    Route::post('/templates', [\App\Http\Controllers\Api\TemplateController::class, 'store']);
    Route::delete('/templates/{id}', [\App\Http\Controllers\Api\TemplateController::class, 'destroy']);
    Route::post('/templates/sync', [\App\Http\Controllers\Api\TemplateController::class, 'sync']);

    // Dashboard Stats & Activity Feed
    Route::get('/dashboard/stats', [DashboardController::class, 'getStats']);
    Route::get('/dashboard/feed', [DashboardController::class, 'getActivityFeed']);
    Route::post('/dashboard/estimationdetail', [DashboardController::class, 'refreshEstimationDetail']);

    // Notifications API Resource
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'clearAll']);

    // Admin Panel Actions
    Route::get('/admin/overview', [AdminController::class, 'getOverview']);
    Route::get('/admin/tenants', [AdminController::class, 'listTenants']);
    Route::post('/admin/tenants', [AdminController::class, 'storeTenant']);
    Route::put('/admin/tenants/{tenantId}', [AdminController::class, 'updateTenant']);
    Route::get('/admin/tenants/{tenant}/channels', [AdminController::class, 'listTenantChannels']);
    Route::post('/admin/tenants/{tenantId}/channels', [AdminController::class, 'storeTenantChannel']);
    Route::put('/admin/channels/{channelId}', [AdminController::class, 'updateChannel']);
    Route::delete('/admin/channels/{channelId}', [AdminController::class, 'deleteChannel']);
    Route::post('/admin/channels/{channelId}/override-webhook', [AdminController::class, 'overrideChannelWebhook']);
    Route::get('/admin/tenants/{tenantId}/details', [AdminController::class, 'getTenantDetails']);
    Route::get('/admin/tenants/{tenantId}/template-messages', [AdminController::class, 'getTenantTemplateMessages']);
    Route::post('/admin/tenants/{tenantId}/recalculate-expenses', [AdminController::class, 'recalculateTenantExpenses']);
    Route::post('/admin/tenants/{tenantId}/payment-status', [AdminController::class, 'updateTenantPaymentStatus']);
    Route::get('/admin/roles', [AdminController::class, 'listRoles']);
    Route::get('/admin/users', [AdminController::class, 'listUsers']);
    Route::post('/admin/users', [AdminController::class, 'storeUser']);
    Route::put('/admin/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser']);
});
