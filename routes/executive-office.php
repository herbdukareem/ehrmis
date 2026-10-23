<?php

use App\Http\Controllers\Api\ExecutiveOfficeController as Office;
use Illuminate\Support\Facades\Route;

Route::prefix('executive-office')->group(function () {
    Route::get('/context', [Office::class, 'context']);
    Route::put('/office', [Office::class, 'configure']);
    Route::get('/team', [Office::class, 'team']);
    Route::post('/team', [Office::class, 'assign']);
    Route::delete('/team/{membership}', [Office::class, 'revoke']);
    Route::get('/options', [Office::class, 'options']);
    Route::get('/records', [Office::class, 'records']);
    Route::post('/records', [Office::class, 'store']);
    Route::get('/records/{record}', [Office::class, 'show'])->whereNumber('record');
    Route::put('/records/{record}', [Office::class, 'update'])->whereNumber('record');
    Route::post('/records/{record}/notes', [Office::class, 'note']);
    Route::post('/records/{record}/attachments', [Office::class, 'attach']);
    Route::get('/records/{record}/attachments/{attachment}', [Office::class, 'download']);
    Route::get('/records/{record}/attachments/{attachment}/preview', [Office::class, 'preview']);
    Route::post('/records/{record}/links', [Office::class, 'link']);
    Route::post('/records/{record}/actions', [Office::class, 'act']);
    Route::post('/records/{record}/directives', [Office::class, 'directives']);
    Route::get('/diary', [Office::class, 'diary']);
    Route::get('/notifications', [Office::class, 'notifications']);
    Route::post('/notifications/{notification}/read', [Office::class, 'readNotification']);
    Route::get('/dashboard', [Office::class, 'dashboard']);
    Route::get('/reports', [Office::class, 'reports']);
    Route::get('/reports/export', [Office::class, 'exportReport']);
    Route::post('/briefings/prepare', [Office::class, 'prepareBriefing']);
    Route::get('/records/{record}/print', [Office::class, 'printPack']);
    Route::get('/delegations', [Office::class, 'delegations']);
    Route::post('/delegations', [Office::class, 'delegate']);
    Route::delete('/delegations/{delegation}', [Office::class, 'revokeDelegation']);
    Route::post('/handover', [Office::class, 'handover']);
    Route::post('/handover/pack', [Office::class, 'handoverPack']);
});
