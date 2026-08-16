<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NoteCompositionController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NoteGenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\WordAudioController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/review', [ReviewController::class, 'show'])->name('review.show');

    // Tapping a word to hear it. Throttled because the first tap on a word that
    // nobody has asked for before spends real quota — every tap after that is
    // served from storage.
    Route::post('/speak', WordAudioController::class)
        ->middleware('throttle:60,1')
        ->name('speak');
    Route::post('/review/undo', [ReviewController::class, 'undo'])->name('review.undo');
    Route::post('/review/{card}', [ReviewController::class, 'grade'])->name('review.grade');

    Route::post('/notes/generate', NoteGenerationController::class)
        ->middleware('throttle:20,1')
        ->name('notes.generate');

    Route::post('/notes/{note}/retry-media', [NoteController::class, 'retryMedia'])
        ->name('notes.retry-media');

    // Where a saved sentence becomes a card: look at the picture, listen to the
    // clip, change either, then press the button. Nothing here creates a card
    // on its own.
    Route::controller(NoteCompositionController::class)->group(function () {
        Route::get('/notes/{note}/compose', 'show')->name('notes.compose');
        Route::get('/notes/{note}/compose/status', 'status')->name('notes.compose.status');
        Route::post('/notes/{note}/image', 'chooseImage')->name('notes.image.choose');
        Route::post('/notes/{note}/image/search', 'searchImages')->name('notes.image.search');
        Route::post('/notes/{note}/image/upload', 'uploadImage')->name('notes.image.upload');
        Route::post('/notes/{note}/media/retry', 'retryMedia')->name('notes.media.retry');
        Route::post('/notes/{note}/complete', 'complete')->name('notes.complete');
    });

    Route::resource('notes', NoteController::class)->except('show');

    // Running the probes spends real API quota, so the throttle is part of the
    // authorisation rather than a nicety — Gemini's speech tier is measured in
    // single-digit requests a minute.
    Route::middleware('can:view-diagnostics')->group(function () {
        Route::get('/system', [SystemStatusController::class, 'show'])->name('system.show');
        Route::post('/system', [SystemStatusController::class, 'run'])
            ->middleware('throttle:6,1')
            ->name('system.run');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
