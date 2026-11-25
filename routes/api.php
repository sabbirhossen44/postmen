<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\CookieController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\GlobalVariableController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\SendController;
use App\Http\Controllers\Api\UserSettingController;
use App\Http\Controllers\Api\VariableController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\WorkspaceInvitationController;
use App\Http\Controllers\Api\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

// Public auth routes.
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    Route::apiResource('workspaces', WorkspaceController::class);
    Route::post('/workspaces/{workspace}/transfer', [WorkspaceController::class, 'transfer']);
    Route::get('/workspaces/{workspace}/activity', [WorkspaceController::class, 'activity']);

    // Team workspaces: members and invitations.
    Route::get('/workspaces/{workspace}/members', [WorkspaceMemberController::class, 'index']);
    Route::patch('/workspaces/{workspace}/members/{userId}', [WorkspaceMemberController::class, 'update']);
    Route::delete('/workspaces/{workspace}/members/{userId}', [WorkspaceMemberController::class, 'destroy']);
    Route::post('/workspaces/{workspace}/leave', [WorkspaceMemberController::class, 'leave']);
    Route::post('/workspaces/{workspace}/invite', [WorkspaceInvitationController::class, 'invite']);
    Route::get('/workspaces/{workspace}/invitations', [WorkspaceInvitationController::class, 'index']);
    Route::post('/workspaces/invitations/{token}/accept', [WorkspaceInvitationController::class, 'accept']);
    Route::post('/workspaces/invitations/{invitation}/decline', [WorkspaceInvitationController::class, 'decline']);

    Route::apiResource('collections', CollectionController::class);
    Route::get('/collections/{collection}/export', [CollectionController::class, 'export']);
    Route::get('/collections/{collection}/documentation', [CollectionController::class, 'documentation']);
    Route::post('/collections/{collection}/fork', [CollectionController::class, 'fork']);
    Route::post('/collections/{collection}/duplicate', [CollectionController::class, 'duplicate']);
    Route::post('/collections/import', [CollectionController::class, 'import']);
    Route::post('/collections/{collection}/folders', [FolderController::class, 'store']);
    Route::post('/collections/{collection}/requests', [RequestController::class, 'store']);

    Route::post('/folders', [FolderController::class, 'store']);
    Route::patch('/folders/{folder}', [FolderController::class, 'update']);
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);

    Route::apiResource('requests', RequestController::class)
        ->parameters(['requests' => 'apiRequest']);
    Route::post('/requests/{apiRequest}/duplicate', [RequestController::class, 'duplicate']);
    Route::patch('/requests/{apiRequest}/move', [RequestController::class, 'move']);
    Route::post('/requests/{apiRequest}/examples', [RequestController::class, 'storeExample']);
    Route::delete('/requests/{apiRequest}/examples/{index}', [RequestController::class, 'destroyExample']);

    Route::get('/settings/me', [UserSettingController::class, 'show']);
    Route::patch('/settings/me', [UserSettingController::class, 'update']);

    Route::apiResource('environments', EnvironmentController::class);
    Route::post('/environments/{environment}/activate', [EnvironmentController::class, 'activate']);
    Route::get('/environments/{environment}/resolve', [VariableController::class, 'resolve']);
    Route::get('/environments/{environment}/export', [EnvironmentController::class, 'export']);
    Route::post('/environments/import', [EnvironmentController::class, 'import']);

    Route::apiResource('globals', GlobalVariableController::class)
        ->parameters(['globals' => 'globalVariable']);
    Route::get('/variables/preview', [VariableController::class, 'preview']);

    Route::post('/requests/{apiRequest}/oauth/token', [OAuthController::class, 'exchange']);
    Route::get('/requests/{apiRequest}/code', [CodeController::class, 'show']);
    Route::post('/code/generate', [CodeController::class, 'generate']);

    Route::post('/collections/{collection}/run', [RunController::class, 'store']);
    Route::get('/runs', [RunController::class, 'index']);
    Route::get('/runs/{run}', [RunController::class, 'show']);

    Route::post('/collections/import/curl', [ImportController::class, 'importCurl']);
    Route::post('/collections/import/openapi', [ImportController::class, 'importOpenApi']);
    Route::post('/import/curl', [ImportController::class, 'importCurl']);
    Route::post('/import/openapi', [ImportController::class, 'importOpenApi']);

    Route::get('/cookies', [CookieController::class, 'index']);
    Route::delete('/cookies', [CookieController::class, 'destroyAll']);
    Route::delete('/cookies/{cookie}', [CookieController::class, 'destroy']);

    Route::get('/histories', [HistoryController::class, 'index']);
    Route::get('/histories/{history}', [HistoryController::class, 'show']);
    Route::delete('/histories', [HistoryController::class, 'destroyAll']);
    Route::delete('/histories/{history}', [HistoryController::class, 'destroy']);

    Route::post('/media', [MediaController::class, 'store']);
    Route::get('/media/{media}', [MediaController::class, 'show']);
    Route::delete('/media/{media}', [MediaController::class, 'destroy']);

    Route::post('/send', [SendController::class, 'send']);
});
