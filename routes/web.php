<?php

use App\Modules\Files\Http\Controllers\BlobController;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/agent'));
Route::view('/agent', 'app');
Route::view('/ops', 'app');
Route::view('/auth/accept-invitation', 'app');
Route::view('/auth/reset-password', 'app');
Route::view('/auth/forgot-password', 'app');
Route::get('/demo', function () {
    abort_unless(app()->environment('local', 'testing'), 404);
    $inbox = app(TenantDatabase::class)->asSystem(fn () => DB::table('inboxes')->where('name', '網站客服')->where('status', 'active')->first());
    abort_unless($inbox, 404);

    return view('demo', ['inbox' => $inbox]);
});
Route::get('/widget/{key}', function (string $key) {
    $tenant = app(TenantDatabase::class);
    $inbox = $tenant->asSystem(fn () => DB::table('inboxes')->where('public_key', $key)->where('status', 'active')->first());
    abort_unless($inbox, 404);
    $origins = $tenant->withinWorkspace($inbox->workspace_id, fn () => DB::table('inbox_origins')->where('inbox_id', $inbox->id)->pluck('origin')->all());

    return response()->view('app')->header('Content-Security-Policy', 'frame-ancestors '.($origins ? implode(' ', $origins) : "'none'").';');
});
Route::put('/uploads/{file}', [BlobController::class, 'upload'])->middleware('signed')->withoutMiddleware(ValidateCsrfToken::class)->name('uploadBlob');
Route::get('/downloads/{file}', [BlobController::class, 'download'])->middleware('signed')->name('downloadBlob');
