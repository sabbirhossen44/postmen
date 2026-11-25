<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SendController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Public auth routes.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    Route::apiResource('workspaces', WorkspaceController::class);

    Route::apiResource('collections', CollectionController::class);
    Route::get('/collections/{collection}/export', [CollectionController::class, 'export']);
    Route::post('/collections/import', [CollectionController::class, 'import']);
    Route::post('/collections/{collection}/folders', [FolderController::class, 'store']);
    Route::post('/collections/{collection}/requests', [RequestController::class, 'store']);

    Route::post('/folders', [FolderController::class, 'store']);
    Route::patch('/folders/{folder}', [FolderController::class, 'update']);
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);

    Route::apiResource('requests', RequestController::class)
        ->parameters(['requests' => 'apiRequest']);
    Route::post('/requests/{apiRequest}/duplicate', [RequestController::class, 'duplicate']);

    Route::apiResource('environments', EnvironmentController::class);
    Route::post('/environments/{environment}/activate', [EnvironmentController::class, 'activate']);

    Route::get('/histories', [HistoryController::class, 'index']);
    Route::get('/histories/{history}', [HistoryController::class, 'show']);
    Route::delete('/histories', [HistoryController::class, 'destroyAll']);
    Route::delete('/histories/{history}', [HistoryController::class, 'destroy']);

    Route::post('/media', [MediaController::class, 'store']);
    Route::get('/media/{media}', [MediaController::class, 'show']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    Route::post('/send', [SendController::class, 'send']);
});
