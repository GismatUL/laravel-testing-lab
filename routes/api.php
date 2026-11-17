<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\Delivery\CourierController;
use App\Http\Controllers\Api\V1\Delivery\DeliveryOrderController;
use App\Http\Controllers\Api\V1\Delivery\ReportController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::get('/products',           [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/products',             [ProductController::class, 'store']);
    Route::put('/products/{product}',    [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/cart',                  [CartController::class, 'show']);
    Route::post('/cart/items',           [CartController::class, 'addItem']);
    Route::delete('/cart/items/{item}',  [CartController::class, 'removeItem']);
    Route::delete('/cart',               [CartController::class, 'clear']);

    Route::post('/orders',         [OrderController::class, 'store']);
    Route::get('/orders/{order}',  [OrderController::class, 'show']);

    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Task02 – Courier management (PostgreSQL + PostGIS)
|--------------------------------------------------------------------------
| Prefixed with /delivery because /orders already belongs to the e-commerce
| module. Full URLs: /api/v1/delivery/...
| Left public on purpose for the lab (easy to curl); in production these
| would sit behind auth:sanctum + abilities (dispatcher / courier / customer).
*/
Route::prefix('delivery')->group(function () {
    Route::get('/orders',                     [DeliveryOrderController::class, 'index']);
    Route::post('/orders',                    [DeliveryOrderController::class, 'store']);
    Route::get('/orders/pending-in-area',     [DeliveryOrderController::class, 'pendingInArea']);
    Route::get('/orders/{order}',             [DeliveryOrderController::class, 'show'])->whereNumber('order');
    Route::post('/orders/{order}/assign',     [DeliveryOrderController::class, 'assign'])->whereNumber('order');
    Route::patch('/orders/{order}/status',    [DeliveryOrderController::class, 'updateStatus'])->whereNumber('order');

    Route::get('/couriers/nearest',             [CourierController::class, 'nearest']);
    Route::patch('/couriers/{courier}/location', [CourierController::class, 'updateLocation'])->whereNumber('courier');

    Route::get('/reports/daily',        [ReportController::class, 'daily']);
    Route::get('/reports/monthly',      [ReportController::class, 'monthly']);
    Route::get('/reports/top-couriers', [ReportController::class, 'topCouriers']);
});
