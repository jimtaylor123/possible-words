<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WordController;

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/words', [WordController::class, 'index'])->name('words.index');
Route::get('/words/{word}', [WordController::class, 'show'])->name('words.show');

// Auth routes
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::post('/words/{word}/definitions', [WordController::class, 'storeDefinition'])->name('words.definitions.store');
    Route::post('/definitions/{definition}/vote', [WordController::class, 'voteDefinition'])->name('definitions.vote');
});
