<?php

use App\Http\Controllers\Site\SiteController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\CoverageAreaController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HotspotLogController;
use App\Http\Controllers\Admin\HotspotMonitorController;
use App\Http\Controllers\Admin\L2tpAccountController;
use App\Http\Controllers\Admin\LoadBalanceController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\RadiusUserController;
use App\Http\Controllers\Admin\RouterController;
use App\Http\Controllers\Admin\RouterPersiapanController;
use App\Http\Controllers\Admin\TemplateCetakController;
use App\Http\Controllers\Admin\ThroughputController;
use App\Http\Controllers\Admin\TwoFactorSettingsController;
use App\Http\Controllers\Admin\VoucherCheckController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\VoucherImportController;
use App\Http\Controllers\Admin\VoucherPenjualanController;
use App\Http\Controllers\Admin\VoucherProfileController;
use App\Http\Controllers\Admin\VoucherScriptController;
use App\Http\Controllers\Admin\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('app.domain'))->group(function () {
Route::get('/', [SiteController::class, 'home'])->name('home');
Route::get('/paket', [SiteController::class, 'packages'])->name('site.packages');
Route::get('/coverage', [SiteController::class, 'coverage'])->name('site.coverage');
Route::get('/bantuan', [SiteController::class, 'help'])->name('site.help');

Route::view('/kebijakan-privasi', 'site.privasi')->name('site.privacy');

Route::get('/status', [SiteController::class, 'status'])->name('site.status');
Route::post('/status', [SiteController::class, 'statusLookup'])
    ->middleware('throttle:10,1')
    ->name('site.status.check');

Route::prefix('admin')->group(function () {
Route::middleware(['auth', 'ensure.admin'])->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live', [DashboardController::class, 'live'])->name('dashboard.live');

    Route::resource('user-hotspot', RadiusUserController::class)
        ->parameters(['user-hotspot' => 'radius_user'])
        ->except(['show', 'destroy']);
    Route::patch('user-hotspot/{radius_user}/toggle', [RadiusUserController::class, 'toggle'])
        ->name('user-hotspot.toggle');

    Route::post('user-hotspot/{radius_user}/extend', [RadiusUserController::class, 'extend'])
        ->name('user-hotspot.extend');

    Route::get('user-hotspot/{radius_user}/voucher', [RadiusUserController::class, 'voucher'])
        ->name('user-hotspot.voucher');

    Route::delete('user-hotspot/{radius_user}', [RadiusUserController::class, 'destroy'])
        ->name('user-hotspot.destroy')
        ->middleware('role:admin');

    Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
    Route::get('packages/router/{router}/profil', [PackageController::class, 'profilRouter'])->name('packages.router-profiles');

    Route::middleware('role:admin')->group(function () {
        Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::post('packages/radius', [PackageController::class, 'simpanRadius'])->name('packages.radius');

        Route::get('packages/legacy/{groupname}/edit', [PackageController::class, 'legacyEdit'])->name('packages.legacy-edit');
        Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        Route::patch('packages/{package}/toggle', [PackageController::class, 'toggle'])->name('packages.toggle');
    });

    Route::get('/cek-voucher', [VoucherCheckController::class, 'index'])->name('voucher-check.index');
    Route::get('/cek-voucher/cari', [VoucherCheckController::class, 'cari'])->name('voucher-check.cari');

    Route::get('/voucher', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/voucher/batch/{batch}', [VoucherController::class, 'show'])->name('vouchers.show');
    Route::get('/voucher/batch/{batch}/progress', [VoucherController::class, 'progress'])->name('vouchers.progress');
    Route::get('/voucher/batch/{batch}/cetak', [VoucherController::class, 'print'])->name('vouchers.print');
    Route::get('/voucher/batch/{batch}/csv', [VoucherController::class, 'export'])->name('vouchers.export');
    Route::post('/voucher/batch/{batch}/ditandai-cetak', [VoucherController::class, 'markPrinted'])->name('vouchers.mark-printed');

    Route::middleware('role:admin')->group(function () {
        Route::get('/voucher/router/{router}/opsi', [VoucherController::class, 'routerOptions'])->name('vouchers.router-options');
        Route::post('/voucher', [VoucherController::class, 'store'])->name('vouchers.store');
        Route::post('/voucher/cepat', [VoucherController::class, 'storeCepat'])->name('vouchers.cepat');
        Route::post('/voucher/sinkron', [VoucherController::class, 'reconcile'])->name('vouchers.reconcile');
        Route::post('/voucher/batch/{batch}/sinkron-ulang', [VoucherController::class, 'resync'])->name('vouchers.resync');
        Route::get('/voucher/import/{router}/pratinjau', [VoucherImportController::class, 'pratinjau'])->middleware('throttle:6,1')->name('vouchers.import.preview');
        Route::post('/voucher/import/{router}', [VoucherImportController::class, 'impor'])->middleware('throttle:6,1')->name('vouchers.import');

        Route::delete('/voucher/batch/{batch}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');
        Route::delete('/voucher/kartu/{voucher}', [VoucherController::class, 'destroyVoucher'])->name('vouchers.destroy-one');
        Route::delete('/voucher/batch/{batch}/kartu', [VoucherController::class, 'destroyMany'])->name('vouchers.destroy-many');

        Route::get('/voucher/template', [TemplateCetakController::class, 'index'])->name('voucher-templates.index');
        Route::post('/voucher/template', [TemplateCetakController::class, 'store'])->name('voucher-templates.store');
        Route::post('/voucher/template/pratinjau', [TemplateCetakController::class, 'pratinjau'])->middleware('throttle:60,1')->name('voucher-templates.pratinjau');
        Route::put('/voucher/template/router/{router}', [TemplateCetakController::class, 'aturDefault'])->name('voucher-templates.default');
        Route::put('/voucher/template/{template}', [TemplateCetakController::class, 'update'])->name('voucher-templates.update');
        Route::delete('/voucher/template/{template}', [TemplateCetakController::class, 'destroy'])->name('voucher-templates.destroy');
        Route::post('/voucher/template/{template}/pulihkan', [TemplateCetakController::class, 'pulihkan'])->name('voucher-templates.pulihkan');

        Route::get('/voucher-script', [VoucherScriptController::class, 'index'])->name('voucher-scripts.index');
        Route::get('/voucher-script/{router}', [VoucherScriptController::class, 'status'])->name('voucher-scripts.status');
        Route::get('/voucher-script/{router}/preview', [VoucherScriptController::class, 'preview'])->name('voucher-scripts.preview');
        Route::post('/voucher-script/{router}', [VoucherScriptController::class, 'install'])->name('voucher-scripts.install');

        Route::get('/voucher-script/{router}/opsi-profil', [VoucherProfileController::class, 'options'])->name('voucher-profiles.options');
        Route::post('/voucher-script/{router}/profil', [VoucherProfileController::class, 'store'])->name('voucher-profiles.store');
        Route::put('/voucher-script/{router}/profil', [VoucherProfileController::class, 'update'])->name('voucher-profiles.update');
        Route::delete('/voucher-script/{router}/profil', [VoucherProfileController::class, 'destroy'])->name('voucher-profiles.destroy');
    });

    Route::get('/hotspot-logs', [HotspotLogController::class, 'index'])->name('hotspot-logs.index');
    Route::get('/hotspot-logs/poll', [HotspotLogController::class, 'poll'])->name('hotspot-logs.poll');
    Route::get('/hotspot-logs/router/tonton', [HotspotLogController::class, 'tonton'])->name('hotspot-logs.tonton');

    Route::get('/activity-logs', [ActivityLogController::class, 'index'])
        ->name('activity-logs.index')
        ->middleware('role:admin');

    Route::get('/routers', [RouterController::class, 'index'])->name('routers.index');
    Route::get('/routers/{router}', [RouterController::class, 'show'])->name('routers.show');
    Route::get('/routers/{router}/stats', [RouterController::class, 'stats'])->name('routers.stats');
    Route::get('/routers/{router}/kesehatan', [RouterController::class, 'kesehatan'])->middleware('role:admin')->name('routers.kesehatan');

    Route::get('/hotspot', [HotspotMonitorController::class, 'index'])->name('hotspot.index');
    Route::get('/hotspot/{router}/aktif', [HotspotMonitorController::class, 'data'])->name('hotspot.data');

    Route::middleware('role:admin')->group(function () {
        Route::delete('/hotspot/{router}/aktif/{id}', [HotspotMonitorController::class, 'putuskan'])->where('id', '\*[0-9A-Fa-f]+')->name('hotspot.kick');
        Route::delete('/hotspot/{router}/cookie/{id}', [HotspotMonitorController::class, 'hapusCookie'])->where('id', '\*[0-9A-Fa-f]+')->name('hotspot.cookie');
        Route::get('/penjualan', [VoucherPenjualanController::class, 'index'])->name('penjualan.index');
        Route::get('/penjualan/unduh', [VoucherPenjualanController::class, 'unduh'])->name('penjualan.unduh');
        Route::get('/penjualan/cetak', [VoucherPenjualanController::class, 'cetak'])->name('penjualan.cetak');
    });

    Route::get('/throughput', [ThroughputController::class, 'index'])->name('throughput.index');
    Route::get('/throughput/{router}/live', [ThroughputController::class, 'live'])->name('throughput.live');
    Route::get('/throughput/{router}/history', [ThroughputController::class, 'history'])->name('throughput.history');

    Route::get('/load-balance', [LoadBalanceController::class, 'index'])->name('load-balance.index');
    Route::get('/load-balance/routers', [LoadBalanceController::class, 'routers'])->name('load-balance.routers');
    Route::get('/load-balance/{router}/live', [LoadBalanceController::class, 'live'])->name('load-balance.live');
    Route::get('/load-balance/{router}/history', [LoadBalanceController::class, 'history'])->name('load-balance.history');

    Route::middleware('role:admin')->group(function () {
        Route::get('/load-balance/{router}/config', [LoadBalanceController::class, 'config'])->name('load-balance.config');
        Route::post('/load-balance/{router}/preview', [LoadBalanceController::class, 'preview'])->name('load-balance.preview');
        Route::post('/load-balance/{router}/apply', [LoadBalanceController::class, 'apply'])
            ->middleware('throttle:10,1')
            ->name('load-balance.apply');
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/routers/test-connection', [RouterController::class, 'testConnection'])->name('routers.test-connection');
        Route::post('/routers', [RouterController::class, 'store'])->name('routers.store');
        Route::put('/routers/{router}', [RouterController::class, 'update'])->name('routers.update');
        Route::delete('/routers/{router}', [RouterController::class, 'destroy'])->name('routers.destroy');

        Route::post('/routers/{router}/reboot', [RouterController::class, 'reboot'])->name('routers.reboot');
        Route::get('/routers/{router}/backup', [RouterController::class, 'backup'])->name('routers.backup');

        Route::get('/routers/{router}/siapkan', [RouterPersiapanController::class, 'show'])->name('routers.siapkan');
        Route::get('/routers/{router}/siapkan/periksa', [RouterPersiapanController::class, 'periksa'])->name('routers.siapkan.periksa');
        Route::post('/routers/{router}/akun-panel', [RouterPersiapanController::class, 'akun'])->middleware('throttle:6,1')->name('routers.akun-panel');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/l2tp', [L2tpAccountController::class, 'index'])->name('l2tp.index');
        Route::post('/l2tp', [L2tpAccountController::class, 'store'])->name('l2tp.store');
        Route::put('/l2tp/{l2tp_account}', [L2tpAccountController::class, 'update'])->name('l2tp.update');
        Route::patch('/l2tp/{l2tp_account}/toggle', [L2tpAccountController::class, 'toggle'])->name('l2tp.toggle');
        Route::delete('/l2tp/{l2tp_account}', [L2tpAccountController::class, 'destroy'])->name('l2tp.destroy');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
        Route::get('/whatsapp/gateway/state', [WhatsAppController::class, 'gatewayState'])->name('whatsapp.gateway.state');
        Route::post('/whatsapp/gateway/reconnect', [WhatsAppController::class, 'gatewayReconnect'])->name('whatsapp.gateway.reconnect');
        Route::post('/whatsapp/gateway/reset', [WhatsAppController::class, 'gatewayReset'])->name('whatsapp.gateway.reset');
        Route::post('/whatsapp/send', [WhatsAppController::class, 'send'])->name('whatsapp.send');
        Route::get('/whatsapp/send-status/{track}', [WhatsAppController::class, 'sendStatus'])->name('whatsapp.send-status');
        Route::post('/whatsapp/broadcast', [WhatsAppController::class, 'broadcast'])->name('whatsapp.broadcast');
        Route::get('/whatsapp/broadcast/{broadcast}/status', [WhatsAppController::class, 'broadcastStatus'])->name('whatsapp.broadcast.status');
        Route::post('/whatsapp/broadcast/{broadcast}/retry', [WhatsAppController::class, 'broadcastRetry'])->name('whatsapp.broadcast.retry');
        Route::post('/whatsapp/broadcast/{broadcast}/cancel', [WhatsAppController::class, 'broadcastCancel'])->name('whatsapp.broadcast.cancel');
        Route::get('/whatsapp/contacts/search', [WhatsAppController::class, 'searchContacts'])->name('whatsapp.contacts.search');
        Route::post('/whatsapp/contacts', [WhatsAppController::class, 'storeContact'])->name('whatsapp.contacts.store');
        Route::patch('/whatsapp/contacts/{contact}', [WhatsAppController::class, 'updateContact'])->name('whatsapp.contacts.update');
        Route::delete('/whatsapp/contacts/{contact}', [WhatsAppController::class, 'destroyContact'])->name('whatsapp.contacts.destroy');

        Route::get('/whatsapp/cloud', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'index'])->name('whatsapp.cloud.index');
        Route::post('/whatsapp/cloud/exchange', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'exchange'])->name('whatsapp.cloud.exchange');
        Route::get('/whatsapp/cloud/mulai', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'redirectStart'])->name('whatsapp.cloud.start');
        Route::get('/whatsapp/cloud/callback', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'callback'])->name('whatsapp.cloud.callback');
        Route::post('/whatsapp/cloud/verify', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'verify'])->name('whatsapp.cloud.verify');
        Route::post('/whatsapp/cloud/subscribe', [\App\Http\Controllers\Admin\WhatsAppCloudController::class, 'subscribe'])->name('whatsapp.cloud.subscribe');

        Route::get('/templates', [\App\Http\Controllers\Admin\MessageTemplateController::class, 'index'])->name('message-templates.index');
        Route::put('/templates/{key}', [\App\Http\Controllers\Admin\MessageTemplateController::class, 'update'])->name('message-templates.update');
        Route::post('/templates/{key}/reset', [\App\Http\Controllers\Admin\MessageTemplateController::class, 'reset'])->name('message-templates.reset');

        Route::get('/notifikasi-wa', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'index'])->name('admin-notifications.index');
        Route::post('/notifikasi-wa', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'store'])->name('admin-notifications.store');
        Route::put('/notifikasi-wa/{recipient}', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'update'])->name('admin-notifications.update');
        Route::patch('/notifikasi-wa/{recipient}/toggle', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'toggle'])->name('admin-notifications.toggle');
        Route::post('/notifikasi-wa/{recipient}/test', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'test'])->name('admin-notifications.test');
        Route::delete('/notifikasi-wa/{recipient}', [\App\Http\Controllers\Admin\AdminNotificationController::class, 'destroy'])->name('admin-notifications.destroy');
    });

    Route::get('/profile', [AdminProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile/info', [AdminProfileController::class, 'updateInfo'])->name('profile.update-info');
    Route::patch('/profile/password', [AdminProfileController::class, 'updatePassword'])->name('profile.update-password');

    Route::get('/two-factor', [TwoFactorSettingsController::class, 'index'])->name('two-factor.index');
    Route::post('/two-factor', [TwoFactorSettingsController::class, 'confirm'])->name('two-factor.confirm');
    Route::get('/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
    Route::post('/two-factor/recovery-codes', [TwoFactorSettingsController::class, 'regenerateRecoveryCodes'])->name('two-factor.regenerate-codes');

    Route::delete('/two-factor', [TwoFactorSettingsController::class, 'disable'])
        ->name('two-factor.disable');

    Route::middleware('role:admin')->group(function () {
        Route::get('/billing', [\App\Http\Controllers\Admin\BillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/create', [\App\Http\Controllers\Admin\BillingController::class, 'create'])->name('billing.create');
        Route::post('/billing', [\App\Http\Controllers\Admin\BillingController::class, 'store'])->name('billing.store');
        Route::get('/billing/{invoice}', [\App\Http\Controllers\Admin\BillingController::class, 'show'])->name('billing.show');
        Route::post('/billing/{invoice}/publish', [\App\Http\Controllers\Admin\BillingController::class, 'publish'])->name('billing.publish');
        Route::post('/billing/{invoice}/confirm', [\App\Http\Controllers\Admin\BillingController::class, 'confirm'])->name('billing.confirm');
        Route::post('/billing/{invoice}/cancel', [\App\Http\Controllers\Admin\BillingController::class, 'cancel'])->name('billing.cancel');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/coverage', [CoverageAreaController::class, 'index'])->name('coverage-areas.index');
        Route::post('/coverage', [CoverageAreaController::class, 'store'])->name('coverage-areas.store');
        Route::put('/coverage/{coverage_area}', [CoverageAreaController::class, 'update'])->name('coverage-areas.update');
        Route::patch('/coverage/{coverage_area}/toggle', [CoverageAreaController::class, 'toggle'])->name('coverage-areas.toggle');
        Route::delete('/coverage/{coverage_area}', [CoverageAreaController::class, 'destroy'])->name('coverage-areas.destroy');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/operators', [\App\Http\Controllers\Admin\OperatorController::class, 'index'])->name('operators.index');
        Route::get('/operators/create', [\App\Http\Controllers\Admin\OperatorController::class, 'create'])->name('operators.create');
        Route::post('/operators', [\App\Http\Controllers\Admin\OperatorController::class, 'store'])->name('operators.store');
        Route::get('/operators/{user}/edit', [\App\Http\Controllers\Admin\OperatorController::class, 'edit'])->name('operators.edit');
        Route::patch('/operators/{user}', [\App\Http\Controllers\Admin\OperatorController::class, 'update'])->name('operators.update');
        Route::patch('/operators/{user}/toggle', [\App\Http\Controllers\Admin\OperatorController::class, 'toggle'])->name('operators.toggle');

        Route::delete('/operators/{user}', [\App\Http\Controllers\Admin\OperatorController::class, 'destroy'])
            ->name('operators.destroy');
    });
});

require __DIR__.'/auth.php';
});

$legacyAdminSections = 'dashboard|user-hotspot|radius-users|packages|routers|whatsapp'
    .'|billing|operators|hotspot-logs|activity-logs|profile|two-factor';

Route::get('/{section}/{rest?}', function (string $section, ?string $rest = null) {
    $section = $section === 'radius-users' ? 'user-hotspot' : $section;

    $target = '/admin/'.$section.($rest !== null && $rest !== '' ? '/'.$rest : '');
    $query  = request()->getQueryString();

    return redirect($target.($query ? '?'.$query : ''), 302, [
        'Cache-Control' => 'no-store, private',
    ]);
})->where('section', $legacyAdminSections)->where('rest', '.*');
});
