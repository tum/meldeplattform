<?php

use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SamlController;
use App\Http\Controllers\SubmitController;
use App\Http\Controllers\TopicAdminController;
use Illuminate\Support\Facades\Route;

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/imprint', [HomeController::class, 'imprint'])->name('imprint');
Route::get('/privacy', [HomeController::class, 'privacy'])->name('privacy');
Route::get('/setLang', [HomeController::class, 'setLang'])->name('lang.set');

// Reporter flow — /submit is rate-limited to blunt storage-exhaustion abuse.
Route::get('/form/{topicID}', [FormController::class, 'show'])->whereNumber('topicID')->name('form.show');
Route::post('/submit', [SubmitController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('form.submit');

// Report view + reply (token-based). `throttle:60,1` blocks brute-force
// token guessing even though the 122-bit UUIDv4 space is infeasible in
// practice.
Route::middleware('throttle:60,1')->group(function (): void {
    Route::get('/report', [ReportController::class, 'show'])->name('report.show');
    Route::post('/report', [ReportController::class, 'reply'])->name('report.reply');
});

// File download – rate-limit to slow exfiltration once a UUID leaks.
Route::get('/file/{name}', [FileController::class, 'download'])
    ->middleware('throttle:60,1')
    ->name('file.download');

// Dev login bypass – requires BOTH non-production env AND the explicit
// `meldeplattform.dev_login_enabled` config flag, so a misconfigured APP_ENV
// alone can't expose it.
if (! app()->environment('production') && (bool) config('meldeplattform.dev_login_enabled', false)) {
    Route::middleware('throttle:5,1')->group(function (): void {
        Route::get('/dev/login', [DevLoginController::class, 'show'])->name('dev.login');
        Route::post('/dev/login', [DevLoginController::class, 'login']);
        Route::get('/dev/logout', [DevLoginController::class, 'logout'])->name('dev.logout');
    });
}

// SAML
Route::get('/saml/metadata', [SamlController::class, 'metadata']);
Route::get('/saml/out', [SamlController::class, 'login'])->middleware('throttle:20,1');
Route::get('/saml/logout', [SamlController::class, 'logout']);
Route::post('/saml/slo', [SamlController::class, 'singleLogout']);
Route::post('/shib', [SamlController::class, 'acs'])->middleware('throttle:20,1');

// Admin of a topic
Route::middleware(['topic.admin'])->group(function (): void {
    Route::get('/newTopic/{topicID}', [TopicAdminController::class, 'newTopic'])->whereNumber('topicID');
    Route::get('/reports/{topicID}', [TopicAdminController::class, 'reportsOfTopic'])->whereNumber('topicID');

    Route::get('/api/topic/{topicID}', [TopicAdminController::class, 'getTopic'])->whereNumber('topicID');
    Route::post('/api/topic/{topicID}', [TopicAdminController::class, 'upsertTopic'])->whereNumber('topicID');
    Route::post('/api/topic/{topicID}/report/{reportID}/status', [TopicAdminController::class, 'setStatus'])
        ->whereNumber('topicID')->whereNumber('reportID');
});
