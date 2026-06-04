<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::get('/products',           [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/products',           [ProductController::class, 'store']);
    Route::put('/products/{product}',  [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/cart',                    [CartController::class, 'show']);
    Route::post('/cart/items',             [CartController::class, 'addItem']);
    Route::delete('/cart/items/{item}',    [CartController::class, 'removeItem']);
    Route::delete('/cart',                 [CartController::class, 'clear']);

    Route::post('/orders',          [OrderController::class, 'store']);
    Route::get('/orders/{order}',   [OrderController::class, 'show']);

    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
});
