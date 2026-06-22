<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\DevLoginController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportAccessController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SamlController;
use App\Http\Controllers\SubmitController;
use App\Http\Controllers\TopicAdminController;
use App\Http\Controllers\UserController;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Public pages
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/imprint', [HomeController::class, 'imprint'])->name('imprint');
Route::get('/privacy', [HomeController::class, 'privacy'])->name('privacy');
Route::post('/setLang', [HomeController::class, 'setLang'])->name('lang.set');

// Reporter flow — /submit is rate-limited to blunt storage-exhaustion abuse.
Route::get('/form/{topic}', [FormController::class, 'show'])->whereNumber('topic')->name('form.show');
Route::post('/submit', [SubmitController::class, 'store'])
    ->middleware('throttle:submit')
    ->name('form.submit');

// Report view + reply (reporter token-based). The `report` limiter blocks
// brute-force token guessing even though the 122-bit UUIDv4 space is
// infeasible in practice.
Route::middleware('throttle:report')->group(function (): void {
    Route::get('/report', [ReportController::class, 'show'])->name('report.show');
    Route::post('/report', [ReportController::class, 'reply'])->name('report.reply');

    // Anonymous return access: a reporter re-enters their one-time receipt
    // code to get back into their report.
    Route::get('/track', [ReportAccessController::class, 'create'])->name('report.track');
    Route::post('/track', [ReportAccessController::class, 'store'])->name('report.track.submit');
});

// File download – rate-limit to slow exfiltration once a UUID leaks.
Route::get('/file/{name}', [FileController::class, 'download'])
    ->middleware('throttle:file-download')
    ->name('file.download');

// Dev login bypass – requires BOTH non-production env AND the explicit
// `meldeplattform.dev_login_enabled` config flag.
if (! app()->environment('production') && (bool) config('meldeplattform.dev_login_enabled', false)) {
    Route::middleware('throttle:dev-login')->group(function (): void {
        Route::get('/dev/login', [DevLoginController::class, 'show'])->name('dev.login');
        Route::post('/dev/login', [DevLoginController::class, 'login'])->name('dev.login.submit');
        Route::get('/dev/logout', [DevLoginController::class, 'logout'])->name('dev.logout');
    });
}

// SAML
Route::get('/saml/metadata', [SamlController::class, 'metadata'])->name('saml.metadata');
Route::get('/saml/out', [SamlController::class, 'login'])
    ->middleware('throttle:saml')->name('saml.login');
Route::get('/saml/logout', [SamlController::class, 'logout'])
    ->middleware('throttle:saml')->name('saml.logout');
Route::match(['get', 'post'], '/saml/slo', [SamlController::class, 'singleLogout'])->name('saml.slo');
Route::post('/shib', [SamlController::class, 'acs'])
    ->middleware('throttle:saml')->name('saml.acs');

// Admin of a topic — `auth` ensures a User is bound; `can:` runs the policy.
Route::middleware('auth')->group(function (): void {
    // Cross-topic admin landing page.
    Route::get('/dashboard', [TopicAdminController::class, 'dashboard'])
        ->name('dashboard');

    Route::get('/dashboard/export', [TopicAdminController::class, 'exportCsv'])
        ->name('dashboard.export');

    Route::get('/newTopic', [TopicAdminController::class, 'create'])
        ->can('create', Topic::class)
        ->name('topic.create');
    Route::get('/api/topic/new', [TopicAdminController::class, 'createSkeleton'])
        ->can('create', Topic::class)
        ->name('topic.create.skeleton');
    Route::post('/api/topic', [TopicAdminController::class, 'store'])
        ->can('create', Topic::class)
        ->name('topic.store');

    // Live summary preview for the editor: renders arbitrary markdown through
    // the same sanitiser as the public page. Not topic-specific and exposes no
    // data, so the group's `auth` gate is sufficient.
    Route::post('/api/topic/summary-preview', [TopicAdminController::class, 'previewSummary'])
        ->name('topic.summary.preview');

    Route::get('/newTopic/{topic}', [TopicAdminController::class, 'edit'])
        ->whereNumber('topic')->can('update', 'topic')->name('topic.edit');
    Route::get('/reports/{topic}', [TopicAdminController::class, 'reportsOfTopic'])
        ->whereNumber('topic')->can('view', 'topic')->name('topic.reports');

    // Admin report view and reply — requires login + topic membership.
    Route::get('/reports/{topic}/{report}', [TopicAdminController::class, 'showReport'])
        ->whereNumber('topic')->whereNumber('report')
        ->scopeBindings()
        ->can('view', 'topic')
        ->name('admin.report.show');
    Route::post('/reports/{topic}/{report}/reply', [TopicAdminController::class, 'replyToReport'])
        ->whereNumber('topic')->whereNumber('report')
        ->scopeBindings()
        ->can('view', 'topic')
        ->middleware('throttle:admin-write')
        ->name('admin.report.reply');

    Route::get('/api/topic/{topic}', [TopicAdminController::class, 'show'])
        ->whereNumber('topic')->can('view', 'topic')->name('topic.show');
    Route::post('/api/topic/{topic}', [TopicAdminController::class, 'update'])
        ->whereNumber('topic')->can('update', 'topic')->name('topic.update');
    Route::post('/api/topic/{topic}/report/{report}/status', [TopicAdminController::class, 'setStatus'])
        ->whereNumber('topic')->whereNumber('report')
        ->scopeBindings()
        ->can('update', 'topic')
        ->middleware('throttle:admin-write')
        ->name('report.status');
    Route::post('/api/topic/{topic}/report/{report}/acknowledge', [TopicAdminController::class, 'acknowledge'])
        ->whereNumber('topic')->whereNumber('report')
        ->scopeBindings()
        ->can('update', 'topic')
        ->middleware('throttle:admin-write')
        ->name('report.acknowledge');
    Route::post('/api/topic/{topic}/reports/status', [TopicAdminController::class, 'bulkSetStatus'])
        ->whereNumber('topic')
        ->can('update', 'topic')
        ->middleware('throttle:admin-write')
        ->name('report.status.bulk');

    Route::middleware('can:manage,'.User::class)->group(function (): void {
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{uid}/edit', [UserController::class, 'edit'])
            ->whereAlphaNumeric('uid')->name('users.edit');
        Route::post('/users/{uid}', [UserController::class, 'update'])
            ->whereAlphaNumeric('uid')->name('users.update');
        Route::delete('/users/{uid}', [UserController::class, 'destroy'])
            ->whereAlphaNumeric('uid')->name('users.destroy');
    });
});
