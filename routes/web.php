<?php

use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SamlController;
use App\Http\Controllers\SubmitController;
use App\Http\Controllers\TopicAdminController;
use App\Models\Topic;
use Illuminate\Support\Facades\Route;

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/imprint', [HomeController::class, 'imprint'])->name('imprint');
Route::get('/privacy', [HomeController::class, 'privacy'])->name('privacy');
Route::get('/setLang', [HomeController::class, 'setLang'])->name('lang.set');

// Reporter flow — /submit is rate-limited to blunt storage-exhaustion abuse.
Route::get('/form/{topic}', [FormController::class, 'show'])->whereNumber('topic')->name('form.show');
Route::post('/submit', [SubmitController::class, 'store'])
    ->middleware('throttle:submit')
    ->name('form.submit');

// Report view + reply (token-based). The `report` limiter blocks brute-force
// token guessing even though the 122-bit UUIDv4 space is infeasible in
// practice.
Route::middleware('throttle:report')->group(function (): void {
    Route::get('/report', [ReportController::class, 'show'])->name('report.show');
    Route::post('/report', [ReportController::class, 'reply'])->name('report.reply');
});

// File download – rate-limit to slow exfiltration once a UUID leaks.
Route::get('/file/{name}', [FileController::class, 'download'])
    ->middleware('throttle:file-download')
    ->name('file.download');

// Dev login bypass – requires BOTH non-production env AND the explicit
// `meldeplattform.dev_login_enabled` config flag, so a misconfigured APP_ENV
// alone can't expose it.
if (! app()->environment('production') && (bool) config('meldeplattform.dev_login_enabled', false)) {
    Route::middleware('throttle:dev-login')->group(function (): void {
        Route::get('/dev/login', [DevLoginController::class, 'show'])->name('dev.login');
        Route::post('/dev/login', [DevLoginController::class, 'login']);
        Route::get('/dev/logout', [DevLoginController::class, 'logout'])->name('dev.logout');
    });
}

// SAML
Route::get('/saml/metadata', [SamlController::class, 'metadata']);
Route::get('/saml/out', [SamlController::class, 'login'])->middleware('throttle:saml');
Route::get('/saml/logout', [SamlController::class, 'logout']);
// HTTP-Redirect binding uses GET; HTTP-POST binding uses POST. Accept both
// so the SP works regardless of which binding the IdP picks at runtime.
Route::match(['get', 'post'], '/saml/slo', [SamlController::class, 'singleLogout']);
Route::post('/shib', [SamlController::class, 'acs'])->middleware('throttle:saml');

// Admin of a topic — `auth` ensures a User is bound; `can:` runs the policy.
Route::middleware('auth')->group(function (): void {
    // Cross-topic admin landing page: every report the user can see.
    Route::get('/dashboard', [TopicAdminController::class, 'dashboard'])
        ->name('dashboard');

    // Create-new lives on its own URL so route-model binding can handle the
    // edit case without colliding with the `0`-sentinel that used to mean
    // "no topic yet".
    Route::get('/newTopic', [TopicAdminController::class, 'create'])
        ->can('create', Topic::class)
        ->name('topic.create');
    Route::get('/api/topic/new', [TopicAdminController::class, 'createSkeleton'])
        ->can('create', Topic::class)
        ->name('topic.create.skeleton');
    Route::post('/api/topic', [TopicAdminController::class, 'store'])
        ->can('create', Topic::class)
        ->name('topic.store');

    Route::get('/newTopic/{topic}', [TopicAdminController::class, 'edit'])
        ->whereNumber('topic')->can('update', 'topic')->name('topic.edit');
    Route::get('/reports/{topic}', [TopicAdminController::class, 'reportsOfTopic'])
        ->whereNumber('topic')->can('view', 'topic')->name('topic.reports');
    Route::get('/api/topic/{topic}', [TopicAdminController::class, 'show'])
        ->whereNumber('topic')->can('view', 'topic')->name('topic.show');
    Route::post('/api/topic/{topic}', [TopicAdminController::class, 'update'])
        ->whereNumber('topic')->can('update', 'topic')->name('topic.update');
    Route::post('/api/topic/{topic}/report/{report}/status', [TopicAdminController::class, 'setStatus'])
        ->whereNumber('topic')->whereNumber('report')
        ->scopeBindings()
        ->can('update', 'topic')
        ->name('report.status');
});
