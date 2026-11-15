<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\ChatAnalyticsController;
use App\Http\Controllers\Admin\CreditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\FlagController;
use App\Http\Controllers\Admin\ModelController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\BrandAdminController;
use App\Http\Controllers\Admin\LicenseAdminController;
use App\Http\Controllers\Admin\PluginReviewController;
use App\Http\Controllers\Admin\ResellerAdminController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoutingController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\SupportAdminController;
use App\Http\Controllers\Admin\TrialController;
use App\Http\Controllers\Admin\UsageController;
use Illuminate\Support\Facades\Route;

// Prefix /admin, names admin.*, middleware (auth, verified, password.confirm, AdminAccess) are set in AdminServiceProvider.
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/search', SearchController::class)->name('search');

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::post('/users', [UserController::class, 'store'])->name('users.store');
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
Route::post('/users/{id}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
Route::post('/users/{id}/activate', [UserController::class, 'activate'])->name('users.activate');
Route::post('/users/{id}/revoke-access', [UserController::class, 'revokeAccess'])->name('users.revoke');
Route::post('/users/{id}/send-reset', [UserController::class, 'sendReset'])->name('users.reset');
Route::put('/users/{id}/platform-role', [UserController::class, 'platformRole'])->name('users.platform_role');
Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');

Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
Route::get('/tenants/{id}', [TenantController::class, 'show'])->name('tenants.show');
Route::post('/tenants/{id}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
Route::post('/tenants/{id}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
Route::delete('/tenants/{id}', [TenantController::class, 'destroy'])->name('tenants.destroy');
Route::post('/tenants/{id}/restore', [TenantController::class, 'restore'])->name('tenants.restore');
Route::post('/tenants/{id}/plan', [TenantController::class, 'assignPlan'])->name('tenants.plan');
Route::post('/tenants/{id}/overrides', [TenantController::class, 'saveOverride'])->name('tenants.overrides.save');
Route::delete('/tenants/{id}/overrides/{key}', [TenantController::class, 'deleteOverride'])->name('tenants.overrides.delete');

Route::get('/chat', [ChatAnalyticsController::class, 'index'])->name('chat.index');

Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
Route::post('/channels/whatsapp', [ChannelController::class, 'storeWhatsapp'])->name('channels.store_whatsapp');
Route::post('/channels/{id}/activate', [ChannelController::class, 'activate'])->name('channels.activate');
Route::post('/channels/{id}/deactivate', [ChannelController::class, 'deactivate'])->name('channels.deactivate');
Route::post('/channels/{id}/rotate-secret', [ChannelController::class, 'rotateSecret'])->name('channels.rotate');
Route::post('/channels/{id}/check', [ChannelController::class, 'check'])->name('channels.check');
Route::get('/credits', [CreditController::class, 'index'])->name('credits.index');
Route::post('/credits/packages', [CreditController::class, 'storePackage'])->name('credits.packages.store');
Route::put('/credits/packages/{id}', [CreditController::class, 'updatePackage'])->name('credits.packages.update');
Route::post('/credits/costs', [CreditController::class, 'storeCost'])->name('credits.costs.store');
Route::post('/tenants/{id}/credits/adjust', [CreditController::class, 'adjust'])->name('tenants.credits.adjust');

Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
Route::put('/billing/settings', [BillingController::class, 'saveSettings'])->name('billing.settings');
Route::post('/billing/coupons', [BillingController::class, 'storeCoupon'])->name('billing.coupons.store');
Route::post('/billing/coupons/{id}/status', [BillingController::class, 'couponStatus'])->name('billing.coupons.status');
Route::post('/billing/orders/{id}/refund', [BillingController::class, 'refund'])->name('billing.refund');
Route::post('/billing/orders/{id}/close-review', [BillingController::class, 'closeReview'])->name('billing.close_review');

Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
Route::put('/roles/{name}', [RoleController::class, 'update'])->name('roles.update');

Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
Route::get('/plans/{id}', [PlanController::class, 'show'])->name('plans.show');
Route::put('/plans/{id}', [PlanController::class, 'update'])->name('plans.update');
Route::put('/plans/{id}/prices', [PlanController::class, 'savePrices'])->name('plans.prices');
Route::put('/plans/{id}/features', [PlanController::class, 'saveFeatures'])->name('plans.features');

Route::get('/features', [FeatureController::class, 'index'])->name('features.index');
Route::post('/features', [FeatureController::class, 'store'])->name('features.store');
Route::put('/features/{key}', [FeatureController::class, 'update'])->name('features.update');
Route::delete('/features/{key}', [FeatureController::class, 'destroy'])->name('features.destroy');

Route::get('/licenses', [LicenseAdminController::class, 'index'])->name('licenses.index');
Route::post('/licenses', [LicenseAdminController::class, 'set'])->middleware('throttle:20,1')->name('licenses.set');
Route::post('/licenses/{id}/remove', [LicenseAdminController::class, 'remove'])->middleware('throttle:20,1')->name('licenses.remove');

Route::get('/brands', [BrandAdminController::class, 'index'])->name('brands.index');
Route::post('/brands', [BrandAdminController::class, 'enable'])->middleware('throttle:20,1')->name('brands.enable');
Route::post('/brands/{id}/disable', [BrandAdminController::class, 'disable'])->middleware('throttle:20,1')->name('brands.disable');

Route::get('/resellers', [ResellerAdminController::class, 'index'])->name('resellers.index');
Route::post('/resellers', [ResellerAdminController::class, 'store'])->middleware('throttle:20,1')->name('resellers.store');
Route::post('/resellers/{id}', [ResellerAdminController::class, 'update'])->middleware('throttle:30,1')->name('resellers.update');
Route::post('/resellers/{id}/payout', [ResellerAdminController::class, 'payout'])->middleware('throttle:30,1')->name('resellers.payout');

Route::get('/plugins', [PluginReviewController::class, 'index'])->name('plugins.index');
Route::post('/plugins/{id}/decide', [PluginReviewController::class, 'decide'])->middleware('throttle:30,1')->name('plugins.decide');

Route::get('/flags', [FlagController::class, 'index'])->name('flags.index');
Route::post('/flags', [FlagController::class, 'store'])->name('flags.store');
Route::put('/flags/{key}', [FlagController::class, 'update'])->name('flags.update');
Route::delete('/flags/{key}', [FlagController::class, 'destroy'])->name('flags.destroy');

Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
Route::put('/settings/{group}', [SettingController::class, 'update'])->name('settings.update');

Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant.index');
Route::post('/assistant', [AssistantController::class, 'ask'])->middleware('throttle:60,1')->name('assistant.ask');
Route::post('/assistant/{id}/confirm', [AssistantController::class, 'confirm'])->middleware('throttle:30,1')->name('assistant.confirm');
Route::post('/assistant/cancel', [AssistantController::class, 'cancel'])->name('assistant.cancel');

Route::get('/support', [SupportAdminController::class, 'index'])->name('support.index');
Route::get('/support/{id}', [SupportAdminController::class, 'show'])->name('support.show');
Route::post('/support/{id}/reply', [SupportAdminController::class, 'reply'])->middleware('throttle:60,1')->name('support.reply');
Route::post('/support/{id}/status', [SupportAdminController::class, 'status'])->name('support.status');

Route::get('/trials', [TrialController::class, 'index'])->name('trials.index');
Route::post('/trials', [TrialController::class, 'grant'])->middleware('throttle:30,1')->name('trials.grant');
Route::post('/trials/{id}/extend', [TrialController::class, 'extend'])->name('trials.extend');
Route::post('/trials/{id}/end', [TrialController::class, 'end'])->name('trials.end');

Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');

Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware('throttle:10,1')->name('broadcasts.store');

Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

Route::get('/providers', [ProviderController::class, 'index'])->name('providers.index');
Route::post('/providers', [ProviderController::class, 'store'])->name('providers.store');
Route::get('/providers/{id}', [ProviderController::class, 'show'])->name('providers.show');
Route::put('/providers/{id}', [ProviderController::class, 'update'])->name('providers.update');
Route::post('/providers/{id}/credential', [ProviderController::class, 'saveCredential'])->name('providers.credential.save');
Route::delete('/providers/{id}/credential', [ProviderController::class, 'revokeCredential'])->name('providers.credential.revoke');
Route::post('/providers/{id}/test', [ProviderController::class, 'test'])->middleware('throttle:10,1')->name('providers.test');
Route::get('/models', [ModelController::class, 'index'])->name('models.index');
Route::post('/providers/{id}/models', [ModelController::class, 'store'])->name('models.store');
Route::put('/models/{id}', [ModelController::class, 'update'])->name('models.update');
Route::post('/models/{id}/pricing', [ModelController::class, 'addPricing'])->name('models.pricing');
Route::put('/models/{id}/access', [ModelController::class, 'saveAccess'])->name('models.access');

Route::get('/routing', [RoutingController::class, 'index'])->name('routing.index');
Route::post('/routing', [RoutingController::class, 'store'])->name('routing.store');
Route::put('/routing/{id}', [RoutingController::class, 'update'])->name('routing.update');
Route::delete('/routing/{id}', [RoutingController::class, 'destroy'])->name('routing.destroy');
