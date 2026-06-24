<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WordController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [WordController::class, 'index'])->name('home');
Route::get('/about', [AboutController::class, 'index'])->name('about');
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
