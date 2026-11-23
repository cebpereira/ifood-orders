<?php

declare(strict_types=1);

use App\Http\Controllers\OrderActionController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [OrderController::class, 'index'])->name('orders.index');
Route::post('/orders/{order}/actions', [OrderActionController::class, 'store'])->name('orders.actions.store');
Route::get('/orders/{order}/cancellation-reasons', [OrderActionController::class, 'cancellationReasons'])->name('orders.cancellation-reasons');
