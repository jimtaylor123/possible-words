<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WordController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
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
    Route::post('/words/{word}/favourite', [WordController::class, 'toggleFavourite'])->name('words.favourite');
    Route::get('/favourites', [WordController::class, 'favourites'])->name('words.favourites');
});

// Serve built assets (for serverless where no static file serving is available)
Route::get('/build/{path}', function (string $path) {
    $file = public_path('build/'.$path);
    if (! file_exists($file) || is_dir($file)) {
        abort(404);
    }
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mime = match ($ext) {
        'js' => 'text/javascript',
        'css' => 'text/css',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain',
        default => finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file) ?: 'application/octet-stream',
    };

    return response()->file($file, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*');

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->group(function () {
    Route::get('/', function () {
        return inertia('Admin/Dashboard');
    })->name('admin.dashboard');
});

// Testing-only helpers. Registered only when running the Pest suite
// (APP_ENV=testing) or when E2E_AUTH_ENABLED=true (see config/app.php).
// The CSRF exemption is registered here too because routes/web.php is loaded after
// the environment variables, unlike the withMiddleware closure in bootstrap/app.php.
if (app()->environment('testing') || config('app.e2e_auth_enabled')) {
    ValidateCsrfToken::except(['testing/login', 'testing/logout']);

    Route::middleware('web')->group(function () {
        Route::post('/testing/login', [AuthController::class, 'testingLogin'])->name('testing.login');
        Route::post('/testing/logout', [AuthController::class, 'testingLogout'])->name('testing.logout');
    });
}
