<?php

use App\Game\Realtime;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\StatsController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = ['connected' => true, 'name' => DB::connection()->getDatabaseName()];
    } catch (Throwable) {
        $database = ['connected' => false];
    }

    return response()->json([
        'app' => config('app.name'),
        'status' => $database['connected'] ? 'ok' : 'degraded',
        'database' => $database,
        'realtime' => Realtime::enabled() ? 'pusher' : 'off',
        'time' => now()->toIso8601String(),
    ], $database['connected'] ? 200 : 503);
});

Route::middleware('throttle:180,1')->group(function () {
    Route::post('/rooms', [RoomController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/rooms/{code}/join', [RoomController::class, 'join'])->middleware('throttle:30,1');
    Route::get('/rooms/{code}', [RoomController::class, 'show']);
    Route::patch('/rooms/{code}', [RoomController::class, 'update']);
    Route::patch('/rooms/{code}/me', [RoomController::class, 'me']);
    Route::post('/rooms/{code}/start', [RoomController::class, 'start']);
    Route::post('/rooms/{code}/location', [RoomController::class, 'location']);
    Route::post('/rooms/{code}/chat', [RoomController::class, 'chat']);
    Route::post('/rooms/{code}/chat/{message}/report', [RoomController::class, 'report'])->whereNumber('message')->middleware('throttle:20,1');
    Route::post('/rooms/{code}/players/{player}/block', [RoomController::class, 'block'])->whereNumber('player')->middleware('throttle:20,1');
    Route::post('/rooms/{code}/power-ups', [RoomController::class, 'powerUp']);
    Route::post('/rooms/{code}/tagged', [RoomController::class, 'tagged']);
    Route::post('/rooms/{code}/surrender', [RoomController::class, 'surrender']);
    Route::post('/rooms/{code}/end', [RoomController::class, 'end']);
    Route::post('/rooms/{code}/leave', [RoomController::class, 'leave']);
    Route::post('/realtime/auth', [RealtimeController::class, 'auth']);

    Route::post('/auth/apple', [AuthController::class, 'apple'])->middleware('throttle:20,1');
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:20,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/me/stats', [StatsController::class, 'show']);
        Route::post('/me/demo-results', [StatsController::class, 'storeDemo']);
        Route::patch('/me', [AuthController::class, 'update']);
        Route::post('/me/daily-power-up', [AuthController::class, 'claimDaily']);
        Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('/me', [AuthController::class, 'destroy']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/push-tokens', [AuthController::class, 'registerPush']);
        Route::delete('/push-tokens', [AuthController::class, 'forgetPush']);
    });
});
