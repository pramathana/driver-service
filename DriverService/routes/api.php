<?php

use App\Http\Controllers\DriverController;
use Illuminate\Support\Facades\Route;

Route::apiResource('drivers', DriverController::class);
Route::post('drivers/assign', [DriverController::class, 'assign']);