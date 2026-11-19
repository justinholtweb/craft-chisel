<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\Chisel\Chisel;
use JustinHoltWeb\Chisel\Http\Controllers\CommandsController;
use JustinHoltWeb\Chisel\Http\Controllers\RunsController;
use JustinHoltWeb\Chisel\Http\Controllers\SettingsController;
use JustinHoltWeb\Chisel\Http\Middleware\EnsureChiselAccess;

// The cp group already applies auth + can:accessCp and the `web` stack (session, CSRF).
// Every route also needs EnsureChiselAccess (admin, not impersonating); run/history routes
// check their permission, and the controllers check the command and elevated session.
Route::middleware(EnsureChiselAccess::class)->prefix('chisel')->group(function () {
    Route::get('', [CommandsController::class, 'index'])->name('chisel.commands');
    Route::post('elevate', [CommandsController::class, 'elevate']);

    Route::middleware('can:'.Chisel::PERMISSION_RUN)->group(function () {
        Route::get('commands/{name}', [CommandsController::class, 'show'])->where('name', '[a-z0-9:_.\-]+');
        Route::post('commands/{name}', [CommandsController::class, 'run'])->where('name', '[a-z0-9:_.\-]+');
        Route::post('catalog/refresh', [CommandsController::class, 'refreshCatalog']);
    });

    // Run pages: the history permission, or the person who queued the run (checked in the controller).
    Route::get('runs/{id}', [RunsController::class, 'show'])->whereNumber('id');
    Route::get('runs/{id}/poll', [RunsController::class, 'poll'])->whereNumber('id');

    Route::middleware('can:'.Chisel::PERMISSION_HISTORY)->group(function () {
        Route::get('history', [RunsController::class, 'index']);
        Route::get('history/export', [RunsController::class, 'export']);
    });

    Route::get('settings', [SettingsController::class, 'edit']);
    Route::post('settings', [SettingsController::class, 'save']);
});
