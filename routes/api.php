<?php

use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\FirewallStatusController;
use App\Http\Middleware\AuthorizeIntegrationToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware([
    'auth:sanctum',
    AuthorizeIntegrationToken::class,
    'throttle:integration-api-token',
])->group(function () {
    Route::get('me', [InventoryController::class, 'me'])->name('me');
    Route::get('companies', [InventoryController::class, 'companies'])->name('companies.index');
    Route::get('companies/{companyId}', [InventoryController::class, 'company'])->where('companyId', '[1-9][0-9]{0,17}')->name('companies.show');
    Route::get('firewalls', [InventoryController::class, 'firewalls'])->name('firewalls.index');
    Route::get('firewalls/status', [FirewallStatusController::class, 'index'])->name('firewalls.status.index');
    Route::get('firewalls/{firewallId}/status', [FirewallStatusController::class, 'show'])->where('firewallId', '[1-9][0-9]{0,17}')->name('firewalls.status.show');
    Route::get('firewalls/{firewallId}', [InventoryController::class, 'firewall'])->where('firewallId', '[1-9][0-9]{0,17}')->name('firewalls.show');
    Route::get('openapi.json', [InventoryController::class, 'openapi'])->name('openapi');
});
