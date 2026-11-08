<?php

use App\Http\Controllers\BenchmarkController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'ok');
Route::get('/bench', [BenchmarkController::class, 'run']);
Route::get('/info', [BenchmarkController::class, 'info']);
