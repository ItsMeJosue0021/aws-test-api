<?php

use App\Http\Controllers\Api\BlogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Unauthenticated on purpose -- this exists to smoke-test the deployment.
// Put it behind `auth:sanctum` before this is anything but a test API.
Route::apiResource('blogs', BlogController::class);
