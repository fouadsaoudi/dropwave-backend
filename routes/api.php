<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelController;
use App\Http\Controllers\Api\WebhookController;

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ConversationController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Meta Webhooks
Route::get('/webhooks/meta', [WebhookController::class, 'verify']);
Route::post('/webhooks/meta', [WebhookController::class, 'receive'])->middleware('meta.webhook.signature');

// Protected routes (Sanctum authed & Tenant scoped)
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Channels
    Route::get('/channels', [ChannelController::class, 'index']);
    Route::post('/channels/connect', [ChannelController::class, 'connect']);
    Route::post('/channels/{id}/override-webhook', [ChannelController::class, 'overrideWebhook']);

    // Conversations
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/counts', [ConversationController::class, 'counts']);
    Route::get('/conversations/{id}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{id}/claim', [ConversationController::class, 'claim']);
    Route::post('/conversations/{id}/resolve', [ConversationController::class, 'resolve']);
    Route::post('/conversations/{id}/reopen', [ConversationController::class, 'reopen']);
    Route::post('/conversations/{id}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('/conversations/{id}/read', [ConversationController::class, 'markAsRead']);
    Route::post('/conversations/send-template', [ConversationController::class, 'sendTemplate']);

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

    // Admin
    Route::get('/admin/tenants', [AdminController::class, 'listTenants']);
    Route::get('/admin/tenants/{tenant}/channels', [AdminController::class, 'listTenantChannels']);
});
