<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureActiveWorkspace;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\LandingController::class, 'show'])->name('home');

// Public: a client's design-approval link. The 256-bit token is the only credential; it shows one picture and takes one answer.
Route::middleware('throttle:60,1')->group(function () {
    $rc = App\Http\Controllers\ReviewController::class;
    Route::get('/review/{token}', [$rc, 'show'])->where('token', '[a-f0-9]{64}')->name('review.show');
    Route::get('/review/{token}/image', [$rc, 'image'])->where('token', '[a-f0-9]{64}')->name('review.image');
    Route::post('/review/{token}', [$rc, 'decide'])->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('review.decide');
});

// Public: the link inside every outreach mail. The 128-bit token is the only credential; GET never changes anything.
Route::get('/u/{token}', [\App\Http\Controllers\UnsubscribeController::class, 'show'])
    ->where('token', '[0-9a-f]{32}')->middleware('throttle:30,1')->name('outreach.unsubscribe');

// Public: the invitee may not be logged in yet.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')->middleware('throttle:invitations')->name('invitations.show');

Route::middleware(['auth', 'verified', EnsureActiveWorkspace::class])->group(function () {
    Route::get('/app', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->where('token', '[a-f0-9]{64}')->middleware('throttle:invitations')->name('invitations.accept');

    Route::middleware(ResolveTenant::class)->prefix('app/models')->group(function () {
        Route::get('/', [\App\Http\Controllers\ModelPreferenceController::class, 'index'])->name('models.index');
        Route::put('/preferences', [\App\Http\Controllers\ModelPreferenceController::class, 'update'])->name('models.preferences');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/knowledge')->group(function () {
        $kc = \App\Http\Controllers\KnowledgeController::class;
        Route::get('/', [$kc, 'index'])->name('knowledge.index');
        Route::post('/', [$kc, 'store'])->middleware('throttle:10,1')->name('knowledge.store');
        Route::post('/{id}', [$kc, 'update'])->middleware('throttle:60,1')->name('knowledge.update');
        Route::post('/{id}/delete', [$kc, 'destroy'])->middleware('throttle:30,1')->name('knowledge.destroy');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/memory')->group(function () {
        $mc = App\Http\Controllers\MemoryController::class;
        Route::get('/', [$mc, 'index'])->name('memory.index');
        Route::post('/', [$mc, 'store'])->middleware('throttle:30,1')->name('memory.store');
        Route::post('/setting', [$mc, 'setting'])->middleware('throttle:20,1')->name('memory.setting');
        Route::post('/clear', [$mc, 'clear'])->middleware('throttle:5,1')->name('memory.clear');
        Route::post('/suggest', [$mc, 'suggest'])->middleware('throttle:6,1')->name('memory.suggest');
        Route::post('/suggestions/{id}/accept', [$mc, 'accept'])->middleware('throttle:30,1')->name('memory.accept');
        Route::post('/suggestions/{id}/dismiss', [$mc, 'dismiss'])->middleware('throttle:30,1')->name('memory.dismiss');
        Route::post('/{id}', [$mc, 'update'])->middleware('throttle:60,1')->name('memory.update');
        Route::post('/{id}/delete', [$mc, 'destroy'])->middleware('throttle:30,1')->name('memory.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/tasks')->group(function () {
        $pc = App\Http\Controllers\ProductivityController::class;
        Route::get('/', [$pc, 'tasks'])->name('tasks.index');
        Route::post('/', [$pc, 'store'])->middleware('throttle:60,1')->name('tasks.store');
        Route::post('/clear-done', [$pc, 'clearDone'])->middleware('throttle:10,1')->name('tasks.clear');
        Route::post('/{id}', [$pc, 'update'])->middleware('throttle:60,1')->name('tasks.update');
        Route::post('/{id}/done', [$pc, 'done'])->middleware('throttle:120,1')->name('tasks.done');
        Route::post('/{id}/delete', [$pc, 'destroy'])->middleware('throttle:60,1')->name('tasks.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/notes')->group(function () {
        $pc = App\Http\Controllers\ProductivityController::class;
        Route::get('/', [$pc, 'notes'])->name('notes.index');
        Route::post('/', [$pc, 'noteStore'])->middleware('throttle:60,1')->name('notes.store');
        Route::post('/{id}', [$pc, 'noteUpdate'])->middleware('throttle:60,1')->name('notes.update');
        Route::post('/{id}/delete', [$pc, 'noteDestroy'])->middleware('throttle:60,1')->name('notes.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/developer')->group(function () {
        $dc = App\Http\Controllers\DeveloperController::class;
        Route::get('/', [$dc, 'index'])->name('developer.index');
        Route::post('/', [$dc, 'store'])->middleware('throttle:10,1')->name('developer.store');
        Route::post('/{id}/revoke', [$dc, 'revoke'])->middleware('throttle:30,1')->name('developer.revoke');
        Route::post('/hooks', [$dc, 'hookStore'])->middleware('throttle:10,1')->name('developer.hook.store');
        Route::post('/hooks/{id}/toggle', [$dc, 'hookToggle'])->middleware('throttle:30,1')->name('developer.hook.toggle');
        Route::post('/hooks/{id}/events', [$dc, 'hookEvents'])->middleware('throttle:30,1')->name('developer.hook.events');
        Route::post('/hooks/{id}/delete', [$dc, 'hookDelete'])->middleware('throttle:30,1')->name('developer.hook.delete');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/agent-builder')->group(function () {
        $c = App\Http\Controllers\AgentStudioController::class;
        Route::get('/', [$c, 'index'])->name('agentbuilder.index');
        Route::get('new', [$c, 'create'])->name('agentbuilder.create');
        Route::post('/', [$c, 'store'])->middleware('throttle:20,1')->name('agentbuilder.store');
        Route::get('{id}', [$c, 'edit'])->name('agentbuilder.edit');
        Route::put('{id}', [$c, 'update'])->middleware('throttle:30,1')->name('agentbuilder.update');
        Route::delete('{id}', [$c, 'destroy'])->middleware('throttle:20,1')->name('agentbuilder.destroy');
        Route::post('{id}/restore', [$c, 'restore'])->middleware('throttle:20,1')->name('agentbuilder.restore');
        Route::post('{id}/test', [$c, 'test'])->middleware('throttle:10,1')->name('agentbuilder.test');
    });
    Route::middleware(ResolveTenant::class)->get('app/license', [App\Http\Controllers\LicenseController::class, 'index'])->name('license.index');
    Route::middleware(ResolveTenant::class)->post('app/mode', [App\Http\Controllers\CompanionController::class, 'set'])->middleware('throttle:30,1')->name('companion.mode');
    Route::middleware(ResolveTenant::class)->prefix('app/computer')->group(function () {
        $c = App\Http\Controllers\PcController::class;
        Route::get('/', [$c, 'index'])->name('pc.index');
        Route::post('pair', [$c, 'pair'])->middleware('throttle:10,1')->name('pc.pair');
        Route::delete('{id}', [$c, 'revoke'])->middleware('throttle:30,1')->name('pc.revoke');
        Route::put('{id}/policy', [$c, 'policy'])->middleware('throttle:30,1')->name('pc.policy');
        Route::post('{id}/request', [$c, 'request'])->middleware('throttle:30,1')->name('pc.request');
        Route::post('actions/{id}/decide', [$c, 'decide'])->middleware('throttle:60,1')->name('pc.decide');
        Route::post('{id}/plan', [$c, 'planSubmit'])->middleware('throttle:20,1')->name('pc.plan.submit');
        Route::post('{id}/plan/draft', [$c, 'planDraft'])->middleware('throttle:6,1')->name('pc.plan.draft');
        Route::post('plans/{id}/decide', [$c, 'planDecide'])->middleware('throttle:60,1')->name('pc.plan.decide');
        Route::post('plans/{id}/step', [$c, 'planStep'])->middleware('throttle:60,1')->name('pc.plan.step');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/characters')->group(function () {
        Route::get('/', [App\Http\Controllers\CharacterController::class, 'index'])->name('characters.index');
        Route::get('{id}/avatar.svg', [App\Http\Controllers\CharacterController::class, 'avatar'])->middleware('throttle:240,1')->name('characters.avatar');
        Route::post('{id}/cues', [App\Http\Controllers\CharacterController::class, 'cues'])->middleware('throttle:60,1')->name('characters.cues');
        Route::post('/', [App\Http\Controllers\CharacterController::class, 'store'])->middleware('throttle:30,1')->name('characters.store');
        Route::put('{id}', [App\Http\Controllers\CharacterController::class, 'update'])->middleware('throttle:30,1')->name('characters.update');
        Route::delete('{id}', [App\Http\Controllers\CharacterController::class, 'destroy'])->middleware('throttle:30,1')->name('characters.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/brand')->group(function () {
        Route::get('/', [App\Http\Controllers\BrandController::class, 'index'])->name('brand.index');
        Route::post('/', [App\Http\Controllers\BrandController::class, 'save'])->middleware('throttle:20,1')->name('brand.save');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/reseller')->group(function () {
        Route::get('/', [App\Http\Controllers\ResellerController::class, 'index'])->name('reseller.index');
        Route::post('/join', [App\Http\Controllers\ResellerController::class, 'join'])->middleware('throttle:10,1')->name('reseller.join');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/plugins')->group(function () {
        $pl = App\Http\Controllers\PluginController::class;
        Route::get('/', [$pl, 'index'])->name('plugins.index');
        Route::post('/submit', [$pl, 'submit'])->middleware('throttle:10,1')->name('plugins.submit');
        Route::post('/versions/{id}/withdraw', [$pl, 'withdraw'])->middleware('throttle:30,1')->name('plugins.withdraw');
        Route::post('/{slug}/install', [$pl, 'install'])->middleware('throttle:20,1')->name('plugins.install');
        Route::post('/{slug}/uninstall', [$pl, 'uninstall'])->middleware('throttle:20,1')->name('plugins.uninstall');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/journey')->group(function () {
        $yc = App\Http\Controllers\JourneyController::class;
        Route::get('/', [$yc, 'index'])->name('journey.index');
        Route::get('/export/{format}', [$yc, 'export'])->where('format', 'md|json')->middleware('throttle:10,1')->name('journey.export');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/skills')->group(function () {
        $kc = App\Http\Controllers\SkillController::class;
        Route::get('/', [$kc, 'index'])->name('skills.index');
        Route::post('/', [$kc, 'store'])->middleware('throttle:20,1')->name('skills.store');
        Route::post('/learn', [$kc, 'learn'])->middleware('throttle:6,1')->name('skills.learn');
        Route::get('/{id}', [$kc, 'show'])->name('skills.show');
        Route::put('/{id}', [$kc, 'update'])->name('skills.update');
        Route::post('/{id}/run', [$kc, 'run'])->middleware('throttle:10,1')->name('skills.run');
        Route::post('/{id}/feedback', [$kc, 'feedback'])->middleware('throttle:30,1')->name('skills.feedback');
        Route::post('/{id}/improve', [$kc, 'improve'])->middleware('throttle:6,1')->name('skills.improve');
        Route::post('/{id}/archive', [$kc, 'archive'])->name('skills.archive');
        Route::post('/{id}/{version}/{action}', [$kc, 'decide'])->where('action', 'approve|reject|restore')->name('skills.decide');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/jobs')->group(function () {
        $jc = App\Http\Controllers\ScheduledJobController::class;
        Route::get('/', [$jc, 'index'])->name('jobs.index');
        Route::post('/', [$jc, 'store'])->middleware('throttle:20,1')->name('jobs.store');
        Route::get('/{id}', [$jc, 'show'])->name('jobs.show');
        Route::post('/{id}/toggle', [$jc, 'toggle'])->name('jobs.toggle');
        Route::post('/{id}/run', [$jc, 'runNow'])->middleware('throttle:6,1')->name('jobs.run');
        Route::delete('/{id}', [$jc, 'destroy'])->name('jobs.destroy');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/photoshop')->group(function () {
        $pc = App\Http\Controllers\PhotoshopAgentController::class;
        Route::get('/', [$pc, 'index'])->name('photoshop.index');
        Route::post('/', [$pc, 'run'])->middleware('throttle:20,1')->name('photoshop.run');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/tools')->group(function () {
        $dc = App\Http\Controllers\DocToolsController::class;
        Route::get('/', [$dc, 'index'])->name('tools.index');
        Route::get('/{tool}', [$dc, 'show'])->where('tool', '[a-z0-9_]+')->name('tools.show');
        Route::post('/{tool}', [$dc, 'run'])->where('tool', '[a-z0-9_]+')->middleware('throttle:20,1')->name('tools.run');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/studio')->group(function () {
        $sc = App\Http\Controllers\StudioController::class;
        Route::get('/', [$sc, 'index'])->name('studio.index');
        Route::post('/', [$sc, 'store'])->middleware('throttle:8,1')->name('studio.store');
        Route::post('/logo', [$sc, 'logo'])->middleware('throttle:4,1')->name('studio.logo');
        Route::get('/{id}', [$sc, 'show'])->name('studio.show');
        Route::get('/{id}/img/{size}', [$sc, 'image'])->where('size', 'post|portrait|story|cover|ad')->middleware('throttle:60,1')->name('studio.image');
        Route::get('/{id}/download/{size}', [$sc, 'download'])->where('size', 'post|portrait|story|cover|ad')->middleware('throttle:30,1')->name('studio.download');
        Route::get('/{id}/zip', [$sc, 'zip'])->middleware('throttle:10,1')->name('studio.zip');
        Route::post('/{id}/edit', [$sc, 'edit'])->middleware('throttle:30,1')->name('studio.edit');
        Route::post('/{id}/versions/{n}/restore', [$sc, 'restore'])->where('n', '[0-9]{1,9}')->middleware('throttle:30,1')->name('studio.restore');
        Route::post('/{id}/share', [$sc, 'share'])->middleware('throttle:10,1')->name('studio.share');
        Route::post('/{id}/reviews/{review}/revoke', [$sc, 'revoke'])->middleware('throttle:30,1')->name('studio.revoke');
        Route::post('/{id}/delete', [$sc, 'destroy'])->middleware('throttle:30,1')->name('studio.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/google-ads')->group(function () {
        $gc = \App\Http\Controllers\GoogleAdsController::class;
        Route::get('/', [$gc, 'index'])->name('gads.index');
        Route::post('/start', [$gc, 'start'])->middleware('throttle:10,1')->name('gads.start');
        Route::get('/callback', [$gc, 'callback'])->middleware('throttle:20,1')->name('gads.callback');
        Route::post('/accounts/{id}/pause', [$gc, 'pause'])->middleware('throttle:20,1')->name('gads.pause');
        Route::post('/accounts/{id}/delete', [$gc, 'disconnect'])->middleware('throttle:20,1')->name('gads.disconnect');
        $gd = \App\Http\Controllers\GadsDraftController::class;
        Route::get('/drafts', [$gd, 'index'])->name('gads.drafts');
        Route::post('/caps', [$gd, 'saveCaps'])->middleware('throttle:10,1')->name('gads.caps');
        Route::get('/drafts/new', [$gd, 'create'])->name('gads.draft.create');
        Route::post('/drafts', [$gd, 'store'])->middleware('throttle:30,1')->name('gads.draft.store');
        Route::post('/drafts/ai', [$gd, 'ai'])->middleware('throttle:10,1')->name('gads.draft.ai');
        Route::get('/drafts/{id}', [$gd, 'edit'])->name('gads.draft');
        Route::post('/drafts/{id}', [$gd, 'update'])->middleware('throttle:30,1')->name('gads.draft.update');
        Route::post('/drafts/{id}/submit', [$gd, 'submit'])->middleware('throttle:30,1')->name('gads.draft.submit');
        Route::post('/drafts/{id}/decide', [$gd, 'decide'])->middleware('throttle:30,1')->name('gads.draft.decide');
        Route::post('/drafts/{id}/launch/retry', [$gd, 'retry'])->middleware('throttle:10,1')->name('gads.draft.retry');
        Route::post('/drafts/{id}/reopen', [$gd, 'reopen'])->middleware('throttle:30,1')->name('gads.draft.reopen');
        Route::post('/drafts/{id}/delete', [$gd, 'destroy'])->middleware('throttle:30,1')->name('gads.draft.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/ads')->group(function () {
        $ac = \App\Http\Controllers\AdsController::class;
        Route::get('/', [$ac, 'index'])->name('ads.index');
        Route::post('/accounts', [$ac, 'connect'])->middleware('throttle:10,1')->name('ads.connect');
        Route::post('/accounts/{id}/pixel', [$ac, 'pixel'])->middleware('throttle:20,1')->name('ads.pixel');
        Route::post('/accounts/{id}/pause', [$ac, 'pause'])->middleware('throttle:20,1')->name('ads.pause');
        Route::post('/accounts/{id}/delete', [$ac, 'disconnect'])->middleware('throttle:20,1')->name('ads.disconnect');
        $dc = \App\Http\Controllers\AdsDraftController::class;
        Route::get('/drafts', [$dc, 'index'])->name('ads.drafts');
        Route::post('/caps', [$dc, 'saveCaps'])->middleware('throttle:10,1')->name('ads.caps');
        Route::get('/drafts/new', [$dc, 'create'])->name('ads.draft.create');
        Route::post('/drafts', [$dc, 'store'])->middleware('throttle:30,1')->name('ads.draft.store');
        Route::post('/drafts/ai', [$dc, 'ai'])->middleware('throttle:10,1')->name('ads.draft.ai');
        Route::get('/drafts/{id}', [$dc, 'edit'])->name('ads.draft');
        Route::post('/drafts/{id}', [$dc, 'update'])->middleware('throttle:30,1')->name('ads.draft.update');
        Route::post('/drafts/{id}/submit', [$dc, 'submit'])->middleware('throttle:30,1')->name('ads.draft.submit');
        Route::post('/drafts/{id}/decide', [$dc, 'decide'])->middleware('throttle:30,1')->name('ads.draft.decide');
        Route::post('/drafts/{id}/page', [$dc, 'page'])->middleware('throttle:30,1')->name('ads.draft.page');
        Route::post('/drafts/{id}/launch/retry', [$dc, 'retry'])->middleware('throttle:10,1')->name('ads.draft.retry');
        Route::post('/drafts/{id}/reopen', [$dc, 'reopen'])->middleware('throttle:30,1')->name('ads.draft.reopen');
        Route::post('/drafts/{id}/delete', [$dc, 'destroy'])->middleware('throttle:30,1')->name('ads.draft.destroy');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/social')->group(function () {
        $sc = App\Http\Controllers\SocialController::class;
        Route::get('/', [$sc, 'index'])->name('social.index');
        Route::get('/new', [$sc, 'create'])->name('social.create');
        Route::post('/', [$sc, 'store'])->middleware('throttle:30,1')->name('social.store');
        Route::post('/caption', [$sc, 'caption'])->middleware('throttle:10,1')->name('social.caption');
        Route::get('/plan', [$sc, 'plan'])->name('social.plan');
        Route::post('/plan', [$sc, 'generate'])->middleware('throttle:5,1')->name('social.plan.generate');
        Route::post('/plan/accept', [$sc, 'accept'])->middleware('throttle:10,1')->name('social.plan.accept');
        Route::get('/growth', [$sc, 'growthPage'])->name('social.growth');
        Route::post('/growth/advice', [$sc, 'growthAdvice'])->middleware('throttle:3,1')->name('social.growth.advice');
        Route::get('/insights', [$sc, 'insights'])->name('social.insights');
        $yc = App\Http\Controllers\SocialYoutubeController::class;
        Route::post('/youtube/start', [$yc, 'start'])->middleware('throttle:10,1')->name('social.youtube.start');
        Route::get('/youtube/callback', [$yc, 'callback'])->middleware('throttle:20,1')->name('social.youtube.callback');
        Route::post('/youtube/suggest', [$yc, 'suggest'])->middleware('throttle:10,1')->name('social.youtube.suggest');
        Route::get('/{id}/youtube', [$yc, 'settings'])->name('social.youtube');
        Route::post('/{id}/youtube', [$yc, 'save'])->middleware('throttle:30,1')->name('social.youtube.save');
        $cc = App\Http\Controllers\SocialCommentController::class;
        Route::get('/comments', [$cc, 'index'])->name('social.comments');
        Route::post('/comments/{id}/draft', [$cc, 'draft'])->middleware('throttle:30,1')->name('social.comments.draft');
        Route::post('/comments/{id}/ai', [$cc, 'ai'])->middleware('throttle:10,1')->name('social.comments.ai');
        Route::post('/comments/{id}/approve', [$cc, 'approve'])->middleware('throttle:30,1')->name('social.comments.approve');
        Route::post('/comments/{id}/withdraw', [$cc, 'withdraw'])->middleware('throttle:30,1')->name('social.comments.withdraw');
        Route::post('/comments/{id}/ignore', [$cc, 'ignore'])->middleware('throttle:30,1')->name('social.comments.ignore');
        Route::post('/comments/{id}/retry', [$cc, 'retry'])->middleware('throttle:30,1')->name('social.comments.retry');
        Route::get('/pages', [$sc, 'accounts'])->name('social.accounts');
        Route::post('/pages', [$sc, 'connect'])->middleware('throttle:10,1')->name('social.connect');
        Route::post('/pages/{id}/delete', [$sc, 'disconnect'])->middleware('throttle:20,1')->name('social.disconnect');
        Route::get('/{id}', [$sc, 'show'])->name('social.show');
        Route::get('/{id}/edit', [$sc, 'edit'])->name('social.edit');
        Route::get('/{id}/media', [$sc, 'media'])->middleware('throttle:60,1')->name('social.media');
        Route::post('/{id}', [$sc, 'update'])->middleware('throttle:30,1')->name('social.update');
        Route::post('/{id}/submit', [$sc, 'submit'])->middleware('throttle:30,1')->name('social.submit');
        Route::post('/{id}/decide', [$sc, 'decide'])->middleware('throttle:30,1')->name('social.decide');
        Route::post('/{id}/retry', [$sc, 'retry'])->middleware('throttle:30,1')->name('social.retry');
        Route::post('/{id}/reopen', [$sc, 'reopen'])->middleware('throttle:30,1')->name('social.reopen');
        Route::post('/{id}/posted', [$sc, 'posted'])->middleware('throttle:30,1')->name('social.posted');
        Route::post('/{id}/cancel', [$sc, 'cancel'])->middleware('throttle:30,1')->name('social.cancel');
        Route::post('/{id}/delete', [$sc, 'destroy'])->middleware('throttle:30,1')->name('social.destroy');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/video')->group(function () {
        $vc = App\Http\Controllers\VideoController::class;
        Route::get('/', [$vc, 'index'])->name('video.index');
        Route::post('/', [$vc, 'store'])->middleware('throttle:6,1')->name('video.store');
        Route::post('/clip', [$vc, 'clip'])->middleware('throttle:4,1')->name('video.clip');
        Route::post('/script', [$vc, 'script'])->middleware('throttle:10,1')->name('video.script');
        Route::get('/{id}', [$vc, 'show'])->name('video.show');
        Route::get('/{id}/file', [$vc, 'file'])->middleware('throttle:60,1')->name('video.file');
        Route::post('/{id}/cancel', [$vc, 'cancel'])->middleware('throttle:30,1')->name('video.cancel');
        Route::post('/{id}/delete', [$vc, 'destroy'])->middleware('throttle:30,1')->name('video.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/connect')->group(function () {
        $cc = App\Http\Controllers\ConnectController::class;
        Route::get('/', [$cc, 'index'])->name('connect.index');
        Route::post('/calendar', [$cc, 'addFeed'])->middleware('throttle:10,1')->name('connect.calendar');
        $co = App\Http\Controllers\CalendarOAuthController::class;
        Route::post('/oauth/{provider}/start', [$co, 'start'])->where('provider', 'google|microsoft')->middleware('throttle:10,1')->name('connect.oauth.start');
        Route::get('/oauth/{provider}/callback', [$co, 'callback'])->where('provider', 'google|microsoft')->middleware('throttle:20,1')->name('connect.oauth.callback');
        Route::post('/event', [$cc, 'addEvent'])->middleware('throttle:20,1')->name('connect.event');
        Route::post('/hook', [$cc, 'addHook'])->middleware('throttle:10,1')->name('connect.hook');
        Route::post('/{id}/test', [$cc, 'test'])->middleware('throttle:10,1')->name('connect.test');
        Route::post('/{id}/delete', [$cc, 'destroy'])->middleware('throttle:30,1')->name('connect.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/voice')->group(function () {
        $vc = App\Http\Controllers\VoiceController::class;
        Route::get('/', [$vc, 'index'])->name('voice.index');
        Route::post('/ask', [$vc, 'ask'])->middleware('throttle:15,1')->name('voice.ask');
        Route::post('/listen', [$vc, 'listen'])->middleware('throttle:15,1')->name('voice.listen');
        Route::post('/speak', [$vc, 'speak'])->middleware('throttle:15,1')->name('voice.speak');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/agents')->group(function () {
        $wc = App\Http\Controllers\WorkflowController::class;
        Route::get('/', [$wc, 'index'])->name('agents.index');
        Route::post('/template', [$wc, 'fromTemplate'])->middleware('throttle:20,1')->name('agents.template');
        Route::post('/create', [$wc, 'create'])->middleware('throttle:20,1')->name('agents.create');
        Route::get('/{id}', [$wc, 'show'])->name('agents.show');
        Route::post('/{id}/rename', [$wc, 'rename'])->middleware('throttle:30,1')->name('agents.rename');
        Route::post('/{id}/settings', [$wc, 'settings'])->middleware('throttle:30,1')->name('agents.settings');
        Route::post('/{id}/steps', [$wc, 'steps'])->middleware('throttle:30,1')->name('agents.steps');
        Route::post('/{id}/state', [$wc, 'state'])->middleware('throttle:30,1')->name('agents.state');
        Route::post('/{id}/duplicate', [$wc, 'duplicate'])->middleware('throttle:20,1')->name('agents.duplicate');
        Route::post('/{id}/test', [$wc, 'test'])->middleware('throttle:10,1')->name('agents.test');
        Route::get('/{id}/edit', [$wc, 'edit'])->name('agents.edit');
        Route::post('/{id}/save', [$wc, 'save'])->middleware('throttle:30,1')->name('agents.save');
        Route::post('/{id}/toggle', [$wc, 'toggle'])->middleware('throttle:30,1')->name('agents.toggle');
        Route::post('/{id}/delete', [$wc, 'destroy'])->middleware('throttle:30,1')->name('agents.destroy');
        Route::post('/{id}/run', [$wc, 'run'])->middleware('throttle:10,1')->name('agents.run');
        Route::post('/runs/{runId}/decide', [$wc, 'decide'])->middleware('throttle:30,1')->name('agents.decide');
        Route::post('/runs/{runId}/cancel', [$wc, 'cancel'])->middleware('throttle:30,1')->name('agents.cancel');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/create')->group(function () {
        $oc = App\Http\Controllers\OfficeController::class;
        Route::get('/', [$oc, 'index'])->name('office.index');
        Route::post('/', [$oc, 'store'])->middleware('throttle:8,1')->name('office.store');
        Route::get('/{id}/download', [$oc, 'download'])->middleware('throttle:30,1')->name('office.download');
        Route::post('/{id}/delete', [$oc, 'destroy'])->middleware('throttle:30,1')->name('office.destroy');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/assist')->group(function () {
        $ac = App\Http\Controllers\AssistController::class;
        Route::get('/', [$ac, 'index'])->name('assist.index');
        Route::post('/', [$ac, 'run'])->middleware('throttle:10,1')->name('assist.run');
        Route::post('/tasks', [$ac, 'addTasks'])->middleware('throttle:20,1')->name('assist.tasks');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/chat')->group(function () {
        $c = \App\Http\Controllers\ChatController::class;
        Route::get('/', [$c, 'index'])->name('chat.index');
        Route::post('/send', [$c, 'send'])->middleware('throttle:30,1')->name('chat.send');
        Route::get('/{id}', [$c, 'show'])->name('chat.show');
        Route::post('/{id}/regenerate', [$c, 'regenerate'])->middleware('throttle:30,1')->name('chat.regenerate');
        Route::patch('/{id}', [$c, 'update'])->name('chat.update');
        Route::delete('/{id}', [$c, 'destroy'])->name('chat.destroy');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/settings/telegram')->group(function () {
        $c = \App\Http\Controllers\ChannelLinkController::class;
        Route::get('/', [$c, 'show'])->name('telegram.show');
        Route::post('/code', [$c, 'code'])->middleware('throttle:10,1')->name('telegram.code');
        Route::delete('/', [$c, 'unlink'])->name('telegram.unlink');
    });
    Route::prefix('app/settings/mobile')->group(function () {
        $c = \App\Http\Controllers\MobilePairController::class;
        Route::get('/', [$c, 'show'])->name('mobile.show');
        Route::post('/pair', [$c, 'create'])->middleware(['password.confirm', 'throttle:10,1'])->name('mobile.pair');
        Route::delete('/devices/{id}', [$c, 'revoke'])->whereNumber('id')->name('mobile.revoke');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/settings/shop')->group(function () {
        $c = \App\Http\Controllers\ShopProfileController::class;
        Route::get('/', [$c, 'show'])->name('shop.show');
        Route::post('/', [$c, 'save'])->name('shop.save');
        Route::post('/logo', [$c, 'uploadLogo'])->middleware('throttle:10,1')->name('shop.logo.upload');
        Route::delete('/logo', [$c, 'removeLogo'])->name('shop.logo.remove');
        Route::get('/logo', [$c, 'logo'])->name('shop.logo');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/support')->group(function () {
        $c = \App\Http\Controllers\SupportController::class;
        Route::get('/', [$c, 'index'])->name('support.index');
        Route::post('/', [$c, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('/{id}', [$c, 'show'])->name('support.show');
        Route::post('/{id}/reply', [$c, 'reply'])->middleware('throttle:30,1')->name('support.reply');
        Route::post('/{id}/close', [$c, 'close'])->name('support.close');
    });
    Route::middleware(ResolveTenant::class)->prefix('app/settings/whatsapp')->group(function () {
        $c = \App\Http\Controllers\ChannelLinkController::class;
        Route::get('/', [$c, 'show'])->defaults('channel', 'whatsapp')->name('whatsapp.show');
        Route::post('/code', [$c, 'code'])->defaults('channel', 'whatsapp')->middleware('throttle:10,1')->name('whatsapp.code');
        Route::delete('/', [$c, 'unlink'])->defaults('channel', 'whatsapp')->name('whatsapp.unlink');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/leads')->group(function () {
        $r = \App\Http\Controllers\LeadResearchController::class;
        $c = \App\Http\Controllers\LeadController::class;
        $b = \App\Http\Controllers\LeadBoardController::class;
        $m = \App\Http\Controllers\LeadCrmController::class;
        $v = \App\Http\Controllers\LeadCsvController::class;
        Route::get('/', [$c, 'index'])->name('leads.index');
        Route::get('/board', [$b, 'board'])->name('leads.board');
        Route::get('/tasks', [$b, 'tasks'])->name('leads.tasks');
        Route::post('/tasks/{taskId}/done', [$m, 'taskDone'])->name('leads.tasks.done');
        Route::get('/import', [$v, 'form'])->name('leads.import');
        Route::post('/import', [$v, 'import'])->middleware('throttle:6,1')->name('leads.import.store');
        Route::get('/export', [$v, 'export'])->middleware('throttle:6,1')->name('leads.export');
        Route::get('/research', [$r, 'index'])->name('leads.research.index');
        Route::post('/research', [$r, 'store'])->middleware('throttle:10,1')->name('leads.research.store');
        Route::get('/research/{id}', [$r, 'show'])->name('leads.research.show');
        Route::post('/research/{id}/cancel', [$r, 'cancel'])->name('leads.research.cancel');
        Route::get('/{id}', [$c, 'show'])->name('leads.show');
        Route::post('/{id}/stage', [$m, 'stage'])->name('leads.stage');
        Route::post('/{id}/notes', [$m, 'note'])->middleware('throttle:60,1')->name('leads.notes.store');
        Route::post('/{id}/tasks', [$m, 'task'])->middleware('throttle:60,1')->name('leads.tasks.store');
        Route::post('/{id}/tags', [$m, 'tags'])->name('leads.tags');
        Route::post('/{id}/follow-up', [$m, 'followUp'])->name('leads.follow-up');
    });

    Route::middleware(ResolveTenant::class)->group(function () {
        $br = \App\Http\Controllers\BrandKitController::class;
        $po = \App\Http\Controllers\PortfolioController::class;
        $pr = \App\Http\Controllers\ProposalController::class;
        $cl = \App\Http\Controllers\ClientController::class;
        $seo = \App\Http\Controllers\SeoController::class;
        Route::get('app/seo', [$seo, 'index'])->name('seo.index');
        Route::post('app/seo', [$seo, 'run'])->middleware('throttle:6,1')->name('seo.run');
        Route::get('app/seo/{id}', [$seo, 'show'])->whereAlphaNumeric('id')->name('seo.show');
        Route::post('app/seo/{id}/delete', [$seo, 'delete'])->middleware('throttle:20,1')->whereAlphaNumeric('id')->name('seo.delete');
        Route::get('app/clients', [$cl, 'index'])->name('clients.index');
        Route::post('app/clients', [$cl, 'save'])->middleware('throttle:30,1')->name('clients.save');
        Route::post('app/clients/assign', [$cl, 'assign'])->middleware('throttle:60,1')->name('clients.assign');
        Route::post('app/clients/{id}/archive', [$cl, 'archive'])->middleware('throttle:20,1')->whereAlphaNumeric('id')->name('clients.archive');
        Route::get('app/brand', [$br, 'show'])->name('brand.show');
        Route::post('app/brand', [$br, 'update'])->middleware('throttle:20,1')->name('brand.update');
        Route::get('app/portfolio', [$po, 'index'])->name('portfolio.index');
        Route::post('app/portfolio', [$po, 'store'])->middleware('throttle:60,1')->name('portfolio.store');
        Route::post('app/portfolio/{id}', [$po, 'update'])->name('portfolio.update');
        Route::post('app/portfolio/{id}/remove', [$po, 'destroy'])->name('portfolio.destroy');
        Route::get('app/reports', [\App\Http\Controllers\ReportController::class, 'index'])->name('reports.index');
        Route::post('app/reports', [\App\Http\Controllers\ReportController::class, 'generate'])->middleware('throttle:10,1')->name('reports.generate');
        Route::get('app/proposals', [$pr, 'index'])->name('proposals.index');
        Route::get('app/proposals/new', [$pr, 'create'])->name('proposals.create');
        Route::post('app/proposals', [$pr, 'store'])->middleware('throttle:30,1')->name('proposals.store');
        Route::get('app/proposals/{id}', [$pr, 'edit'])->name('proposals.edit');
        Route::post('app/proposals/{id}', [$pr, 'update'])->name('proposals.update');
        Route::post('app/proposals/{id}/status', [$pr, 'status'])->name('proposals.status');
        Route::get('app/proposals/{id}/download/{format}', [$pr, 'download'])->middleware('throttle:20,1')->name('proposals.download');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/orders')->group(function () {
        $o = App\Http\Controllers\OrderController::class;
        Route::get('/', [$o, 'index'])->name('orders.index');
        Route::get('new', [$o, 'create'])->name('orders.create');
        Route::post('/', [$o, 'store'])->middleware('throttle:30,1')->name('orders.store');
        Route::get('settings', [$o, 'settings'])->name('orders.settings');
        Route::post('settings', [$o, 'saveSettings'])->middleware('throttle:20,1')->name('orders.settings.save');
        $k = App\Http\Controllers\CourierController::class;
        Route::get('couriers', [$k, 'index'])->name('couriers.index');
        Route::post('couriers/{courier}', [$k, 'save'])->middleware('throttle:20,1')->name('couriers.save');
        Route::post('couriers/{id}/status', [$k, 'toggle'])->middleware('throttle:20,1')->name('couriers.toggle');
        Route::get('shipments', [$k, 'shipmentsIndex'])->name('shipments.index');
        Route::post('shipments/{id}/release', [$k, 'release'])->middleware('throttle:20,1')->name('shipments.release');
        Route::post('shipments/{id}/sync', [$k, 'sync'])->middleware('throttle:30,1')->name('shipments.sync');
        Route::get('report', [$k, 'report'])->name('orders.report');
        Route::get('{id}', [$o, 'show'])->name('orders.show');
        Route::post('{id}/status', [$o, 'transition'])->middleware('throttle:60,1')->name('orders.transition');
        Route::post('{id}/ship', [$k, 'ship'])->middleware('throttle:20,1')->name('orders.ship');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/accounting')->group(function () {
        $a = App\Http\Controllers\AccountingController::class;
        Route::get('/', [$a, 'index'])->name('accounting.index');
        Route::get('accounts', [$a, 'accounts'])->name('accounting.accounts');
        Route::post('accounts/seed', [$a, 'seed'])->middleware('throttle:10,1')->name('accounting.seed');
        Route::post('accounts', [$a, 'storeAccount'])->middleware('throttle:30,1')->name('accounting.accounts.store');
        Route::post('accounts/{id}/active', [$a, 'toggleAccount'])->middleware('throttle:30,1')->name('accounting.accounts.active');
        Route::get('new', [$a, 'create'])->name('accounting.create');
        Route::post('/', [$a, 'store'])->middleware('throttle:60,1')->name('accounting.store');
        Route::get('ledger', [$a, 'ledger'])->name('accounting.ledger');
        Route::get('trial-balance', [$a, 'trial'])->name('accounting.trial');
        Route::get('lock', [$a, 'settings'])->name('accounting.settings');
        Route::post('lock', [$a, 'lock'])->middleware('throttle:10,1')->name('accounting.lock');
        Route::post('unlock', [$a, 'unlock'])->middleware('throttle:10,1')->name('accounting.unlock');
        $x = App\Http\Controllers\AccountingDocController::class;
        Route::get('parties', [$x, 'parties'])->name('accounting.parties');
        Route::post('parties', [$x, 'addParty'])->middleware('throttle:30,1')->name('accounting.parties.add');
        Route::post('parties/{id}/active', [$x, 'toggleParty'])->middleware('throttle:30,1')->name('accounting.parties.active');
        Route::get('docs/{plural}', [$x, 'index'])->name('accounting.docs');
        Route::get('docs/{plural}/new', [$x, 'create'])->name('accounting.docs.create');
        Route::post('docs/{plural}', [$x, 'store'])->middleware('throttle:60,1')->name('accounting.docs.store');
        Route::get('doc/{id}', [$x, 'show'])->name('accounting.doc');
        Route::post('doc/{id}/pay', [$x, 'pay'])->middleware('throttle:60,1')->name('accounting.doc.pay');
        Route::post('doc/{id}/void', [$x, 'voidDoc'])->middleware('throttle:30,1')->name('accounting.doc.void');
        Route::post('payment/{id}/void', [$x, 'voidPayment'])->middleware('throttle:30,1')->name('accounting.payment.void');
        Route::get('aging', [$x, 'aging'])->name('accounting.aging');
        Route::get('cash', [$x, 'cash'])->name('accounting.cash');
        Route::post('cash', [$x, 'markCash'])->middleware('throttle:20,1')->name('accounting.cash.mark');
        $rp = App\Http\Controllers\AccountingReportController::class;
        Route::get('reports/profit-loss', [$rp, 'profitLoss'])->name('accounting.report.pl');
        Route::get('reports/balance-sheet', [$rp, 'balanceSheet'])->name('accounting.report.balance');
        Route::get('reports/cash-flow', [$rp, 'cashFlow'])->name('accounting.report.cashflow');
        $bk = App\Http\Controllers\AccountingBankController::class;
        Route::get('bank', [$bk, 'index'])->name('accounting.bank');
        Route::get('bank/{account}', [$bk, 'show'])->name('accounting.bank.show');
        Route::post('bank/{account}/preview', [$bk, 'preview'])->middleware('throttle:20,1')->name('accounting.bank.preview');
        Route::post('bank/{account}/import', [$bk, 'import'])->middleware('throttle:10,1')->name('accounting.bank.import');
        Route::post('bank/{account}/auto', [$bk, 'auto'])->middleware('throttle:20,1')->name('accounting.bank.auto');
        Route::post('bank-line/{id}/match', [$bk, 'match'])->middleware('throttle:120,1')->name('accounting.bank.match');
        Route::post('bank-line/{id}/unmatch', [$bk, 'unmatch'])->middleware('throttle:120,1')->name('accounting.bank.unmatch');
        Route::post('bank-line/{id}/ignore', [$bk, 'ignore'])->middleware('throttle:120,1')->name('accounting.bank.ignore');
        Route::post('bank-line/{id}/entry', [$bk, 'entry'])->middleware('throttle:120,1')->name('accounting.bank.entry');
        $vt = App\Http\Controllers\AccountingVatController::class;
        Route::get('vat', [$vt, 'index'])->name('accounting.vat');
        Route::post('vat/settings', [$vt, 'saveSettings'])->middleware('throttle:20,1')->name('accounting.vat.settings');
        Route::post('vat/seed', [$vt, 'seed'])->middleware('throttle:10,1')->name('accounting.vat.seed');
        Route::post('vat/rates', [$vt, 'saveRate'])->middleware('throttle:30,1')->name('accounting.vat.rate');
        Route::get('vat/books', [$vt, 'books'])->name('accounting.vat.books');
        Route::get('vat/books/{which}.csv', [$vt, 'csv'])->middleware('throttle:30,1')->name('accounting.vat.csv');
        Route::post('vat/doc/{id}/flag', [$vt, 'flag'])->middleware('throttle:60,1')->name('accounting.vat.flag');
        Route::post('vat/returns', [$vt, 'saveReturn'])->middleware('throttle:30,1')->name('accounting.vat.return');
        Route::post('vat/returns/{id}/file', [$vt, 'fileReturn'])->middleware('throttle:30,1')->name('accounting.vat.file');
        $pr = App\Http\Controllers\AccountingPayrollController::class;
        Route::get('payroll', [$pr, 'index'])->name('accounting.payroll');
        Route::post('payroll/runs', [$pr, 'createRun'])->middleware('throttle:20,1')->name('accounting.payroll.create');
        Route::get('payroll/runs/{id}', [$pr, 'run'])->name('accounting.payroll.run');
        Route::post('payroll/runs/{id}/adjust', [$pr, 'adjust'])->middleware('throttle:60,1')->name('accounting.payroll.adjust');
        Route::post('payroll/runs/{id}/adjust/remove', [$pr, 'adjustRemove'])->middleware('throttle:60,1')->name('accounting.payroll.adjust.remove');
        Route::post('payroll/runs/{id}/post', [$pr, 'post'])->middleware('throttle:20,1')->name('accounting.payroll.post');
        Route::post('payroll/runs/{id}/pay', [$pr, 'pay'])->middleware('throttle:20,1')->name('accounting.payroll.pay');
        Route::post('payroll/runs/{id}/void', [$pr, 'void'])->middleware('throttle:20,1')->name('accounting.payroll.void');
        Route::get('payroll/employees', [$pr, 'employees'])->name('accounting.payroll.employees');
        Route::post('payroll/employees', [$pr, 'saveEmployee'])->middleware('throttle:30,1')->name('accounting.payroll.employee');
        Route::post('payroll/employees/{id}/active', [$pr, 'employeeActive'])->middleware('throttle:30,1')->name('accounting.payroll.employee.active');
        Route::get('payroll/employees/{id}', [$pr, 'structure'])->name('accounting.payroll.structure');
        Route::get('payroll/employees/{id}/pay', [$pr, 'payDetails'])->name('accounting.payroll.pay.details');
        Route::post('payroll/employees/{id}/pay', [$pr, 'savePayDetails'])->middleware('throttle:30,1')->name('accounting.payroll.pay.details.save');
        Route::get('payroll/runs/{id}/slips', [$pr, 'slips'])->name('accounting.payroll.slips');
        Route::get('payroll/runs/{id}/file/{method}', [$pr, 'file'])->middleware('throttle:20,1')->name('accounting.payroll.file');
        Route::post('payroll/employees/{id}/components', [$pr, 'saveComponent'])->middleware('throttle:30,1')->name('accounting.payroll.component');
        Route::post('payroll/employees/{id}/components/{component}/end', [$pr, 'endComponent'])->middleware('throttle:30,1')->name('accounting.payroll.component.end');
        $au = App\Http\Controllers\AccountingAuditController::class;
        $an = App\Http\Controllers\AccountingAnalystController::class;
        Route::get('analyst', [$an, 'index'])->name('accounting.analyst');
        Route::post('analyst', [$an, 'run'])->middleware('throttle:6,1')->name('accounting.analyst.run');
        Route::get('analyst/{id}', [$an, 'show'])->name('accounting.analyst.show');
        Route::get('audit', [$au, 'index'])->name('accounting.audit');
        Route::post('audit/scan', [$au, 'scan'])->middleware('throttle:10,1')->name('accounting.audit.scan');
        Route::post('audit/findings/{id}/review', [$au, 'review'])->middleware('throttle:60,1')->name('accounting.audit.review');
        Route::post('audit/settings', [$au, 'saveSettings'])->middleware('throttle:20,1')->name('accounting.audit.settings');
        Route::post('audit/items', [$au, 'saveItem'])->middleware('throttle:30,1')->name('accounting.audit.item');
        Route::post('audit/items/seed', [$au, 'seedItems'])->middleware('throttle:10,1')->name('accounting.audit.seed');
        Route::post('audit/items/{id}/active', [$au, 'itemActive'])->middleware('throttle:30,1')->name('accounting.audit.item.active');
        Route::post('audit/items/{id}/tick', [$au, 'tick'])->middleware('throttle:60,1')->name('accounting.audit.tick');
        Route::post('audit/items/{id}/untick', [$au, 'untick'])->middleware('throttle:60,1')->name('accounting.audit.untick');
        $tc = App\Http\Controllers\AccountingTaxCalController::class;
        Route::get('calendar', [$tc, 'index'])->name('accounting.cal');
        Route::post('calendar/seed', [$tc, 'seed'])->middleware('throttle:10,1')->name('accounting.cal.seed');
        Route::post('calendar/save', [$tc, 'save'])->middleware('throttle:30,1')->name('accounting.cal.save');
        Route::post('calendar/{id}/active', [$tc, 'active'])->middleware('throttle:30,1')->name('accounting.cal.active');
        Route::post('calendar/{id}/done', [$tc, 'done'])->middleware('throttle:60,1')->name('accounting.cal.done');
        Route::post('calendar/{id}/undo', [$tc, 'undo'])->middleware('throttle:60,1')->name('accounting.cal.undo');
        $tx = App\Http\Controllers\AccountingTaxController::class;
        Route::get('tax', [$tx, 'index'])->name('accounting.tax');
        Route::post('tax/rules', [$tx, 'saveRule'])->middleware('throttle:30,1')->name('accounting.tax.rule');
        Route::get('tax/settle/{doc}', [$tx, 'settleForm'])->name('accounting.tax.settle');
        Route::post('tax/settle/{doc}', [$tx, 'settle'])->middleware('throttle:30,1')->name('accounting.tax.settle.save');
        Route::post('tax/cert/{id}', [$tx, 'cert'])->middleware('throttle:60,1')->name('accounting.tax.cert');
        Route::post('tax/deposit', [$tx, 'deposit'])->middleware('throttle:20,1')->name('accounting.tax.deposit');
        Route::post('tax/advance', [$tx, 'advancePay'])->middleware('throttle:20,1')->name('accounting.tax.advance');
        Route::post('tax/advance/{id}/void', [$tx, 'advanceVoid'])->middleware('throttle:20,1')->name('accounting.tax.advance.void');
        $bs = App\Http\Controllers\AccountingBillScanController::class;
        Route::get('bills/scan', [$bs, 'index'])->name('accounting.billscan');
        Route::post('bills/scan', [$bs, 'upload'])->middleware('throttle:20,1')->name('accounting.billscan.upload');
        Route::get('bills/scan/{id}', [$bs, 'show'])->name('accounting.billscan.show');
        Route::get('bills/scan/{id}/file', [$bs, 'file'])->middleware('throttle:60,1')->name('accounting.billscan.file');
        Route::post('bills/scan/{id}/post', [$bs, 'post'])->middleware('throttle:30,1')->name('accounting.billscan.post');
        Route::post('bills/scan/{id}/discard', [$bs, 'discard'])->middleware('throttle:30,1')->name('accounting.billscan.discard');
        $as = App\Http\Controllers\AccountingAssetController::class;
        Route::get('assets', [$as, 'index'])->name('accounting.assets');
        Route::post('assets', [$as, 'store'])->middleware('throttle:30,1')->name('accounting.assets.store');
        Route::post('assets/run', [$as, 'run'])->middleware('throttle:10,1')->name('accounting.assets.run');
        Route::get('assets/{id}', [$as, 'show'])->name('accounting.assets.show');
        Route::post('assets/{id}/dispose', [$as, 'dispose'])->middleware('throttle:30,1')->name('accounting.assets.dispose');
        Route::get('inventory', [$as, 'inventory'])->name('accounting.inventory');
        Route::post('inventory/cost', [$as, 'setCost'])->middleware('throttle:20,1')->name('accounting.inventory.cost');
        Route::post('inventory/revalue', [$as, 'revalue'])->middleware('throttle:10,1')->name('accounting.inventory.revalue');
        $ap = App\Http\Controllers\AccountingAutopostController::class;
        Route::get('autopost', [$ap, 'index'])->name('accounting.autopost');
        Route::post('autopost/toggle', [$ap, 'toggle'])->middleware('throttle:10,1')->name('accounting.autopost.toggle');
        Route::post('autopost/run', [$ap, 'run'])->middleware('throttle:10,1')->name('accounting.autopost.run');
        Route::post('autopost/{seq}/skip', [$ap, 'skip'])->middleware('throttle:60,1')->name('accounting.autopost.skip');
        Route::post('autopost/settle', [$ap, 'settle'])->middleware('throttle:30,1')->name('accounting.autopost.settle');
        Route::post('autopost/settlement/{id}/void', [$ap, 'voidSettlement'])->middleware('throttle:30,1')->name('accounting.autopost.void');
        Route::get('{id}', [$a, 'show'])->name('accounting.show');
        Route::post('{id}/reverse', [$a, 'reverse'])->middleware('throttle:30,1')->name('accounting.reverse');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/inbox')->group(function () {
        $c = App\Http\Controllers\InboxController::class;
        Route::get('/', [$c, 'index'])->name('inbox.index');
        Route::get('channels', [$c, 'channels'])->name('inbox.channels');
        Route::post('channels', [$c, 'channelStore'])->middleware('throttle:10,1')->name('inbox.channels.store');
        Route::post('channels/external', [$c, 'channelStoreExternal'])->middleware('throttle:10,1')->name('inbox.channels.external');
        Route::post('channels/{id}', [$c, 'channelUpdate'])->middleware('throttle:20,1')->name('inbox.channels.update');
        $a = App\Http\Controllers\InboxAgentController::class;
        Route::get('agent', [$a, 'index'])->name('inbox.agent');
        Route::post('agent', [$a, 'save'])->middleware('throttle:20,1')->name('inbox.agent.save');
        Route::post('{id}/draft/dismiss', [$a, 'dismiss'])->middleware('throttle:60,1')->name('inbox.draft.dismiss');
        Route::get('{id}', [$c, 'show'])->name('inbox.show');
        Route::get('{id}/messages', [$c, 'messages'])->middleware('throttle:120,1')->name('inbox.messages');
        Route::post('{id}/reply', [$c, 'reply'])->middleware('throttle:60,1')->name('inbox.reply');
        Route::post('{id}/mode', [$c, 'mode'])->middleware('throttle:60,1')->name('inbox.mode');
        Route::post('{id}/resolve', [$c, 'resolve'])->middleware('throttle:60,1')->name('inbox.resolve');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/catalog')->group(function () {
        $c = \App\Http\Controllers\CatalogController::class;
        Route::get('/', [$c, 'index'])->name('catalog.index');
        Route::get('new', [$c, 'create'])->name('catalog.create');
        Route::post('/', [$c, 'store'])->middleware('throttle:60,1')->name('catalog.store');
        Route::get('import', [$c, 'importForm'])->name('catalog.import');
        Route::post('import', [$c, 'importStore'])->middleware('throttle:10,1')->name('catalog.import.store');
        Route::get('import/sample.csv', [$c, 'sample'])->name('catalog.sample');
        Route::post('ai-write', [$c, 'aiWrite'])->middleware('throttle:20,1')->name('catalog.ai');
        $sc = \App\Http\Controllers\StoreController::class;
        Route::get('stores', [$sc, 'index'])->name('catalog.stores');
        Route::post('stores', [$sc, 'store'])->middleware('throttle:10,1')->name('catalog.stores.store');
        Route::post('stores/{id}', [$sc, 'update'])->middleware('throttle:20,1')->name('catalog.stores.update');
        Route::post('stores/{id}/toggle', [$sc, 'toggle'])->name('catalog.stores.toggle');
        Route::post('stores/{id}/sync', [$sc, 'sync'])->middleware('throttle:6,1')->name('catalog.stores.sync');
        Route::get('{id}', [$c, 'edit'])->name('catalog.edit');
        Route::post('{id}', [$c, 'update'])->middleware('throttle:60,1')->name('catalog.update');
        Route::post('{id}/delete', [$c, 'destroy'])->name('catalog.destroy');
        Route::post('{id}/flow/{action}', [$c, 'workflow'])->middleware('throttle:60,1')->name('catalog.flow');
        Route::post('{id}/variants', [$c, 'variantStore'])->middleware('throttle:60,1')->name('catalog.variants.store');
        Route::post('{id}/variants/{variant}/archive', [$c, 'variantArchive'])->name('catalog.variants.archive');
        Route::post('{id}/variants/{variant}/stock', [$c, 'stock'])->middleware('throttle:120,1')->name('catalog.stock');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/outreach')->group(function () {
        $ac = \App\Http\Controllers\EmailAccountController::class;
        $oc = \App\Http\Controllers\OutreachController::class;
        Route::get('accounts', [$ac, 'index'])->name('outreach.accounts');
        Route::post('accounts', [$ac, 'store'])->middleware('throttle:10,1')->name('outreach.accounts.store');
        Route::post('accounts/{id}/toggle', [$ac, 'toggle'])->name('outreach.accounts.toggle');
        $oa = \App\Http\Controllers\OutreachOAuthController::class;
        Route::post('oauth/{provider}/start', [$oa, 'start'])->where('provider', 'gmail|graph')->middleware('throttle:10,1')->name('outreach.oauth.start');
        Route::get('oauth/{provider}/callback', [$oa, 'callback'])->where('provider', 'gmail|graph')->middleware('throttle:20,1')->name('outreach.oauth.callback');
        Route::post('dns', [$ac, 'dns'])->middleware('throttle:20,1')->name('outreach.dns');
        Route::get('/', [$oc, 'campaigns'])->name('outreach.campaigns');
        Route::post('campaigns', [$oc, 'store'])->middleware('throttle:30,1')->name('outreach.campaigns.store');
        Route::get('campaigns/{id}', [$oc, 'show'])->name('outreach.campaign');
        Route::post('campaigns/{id}/status', [$oc, 'setStatus'])->name('outreach.campaign.status');
        Route::post('campaigns/{id}/leads', [$oc, 'addLeads'])->middleware('throttle:20,1')->name('outreach.campaign.leads');
        Route::get('queue', [$oc, 'queue'])->name('outreach.queue');
        Route::post('queue', [$oc, 'decide'])->middleware('throttle:60,1')->name('outreach.decide');
        Route::post('messages/{id}/mark', [$oc, 'mark'])->name('outreach.mark');
        Route::get('suppressions', [$oc, 'suppressions'])->name('outreach.suppressions');
        Route::post('suppressions', [$oc, 'suppress'])->middleware('throttle:30,1')->name('outreach.suppress');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/credits')->group(function () {
        Route::get('/', [\App\Http\Controllers\CreditController::class, 'index'])->name('credits.index');
        Route::get('/transactions', [\App\Http\Controllers\CreditController::class, 'transactions'])->name('credits.transactions');
        Route::put('/settings', [\App\Http\Controllers\CreditController::class, 'settings'])->name('credits.settings');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/billing')->group(function () {
        $bc = \App\Http\Controllers\BillingController::class;
        Route::get('/', [$bc, 'index'])->name('billing.index');
        Route::get('credits', [$bc, 'credits'])->name('billing.credits');
        Route::get('checkout', [$bc, 'checkout'])->name('billing.checkout');
        Route::post('pay', [$bc, 'pay'])->middleware('throttle:10,1')->name('billing.pay');
        Route::get('cancelled', [$bc, 'cancelled'])->name('billing.cancelled');
        Route::get('orders', [$bc, 'orders'])->name('billing.orders');
        Route::get('invoices', [$bc, 'invoices'])->name('billing.invoices');
        Route::get('invoices/{id}', [$bc, 'invoice'])->where('id', '[0-9a-z]{26}')->name('billing.invoice');
        Route::get('profile', [$bc, 'profile'])->name('billing.profile');
        Route::put('profile', [$bc, 'saveProfile'])->name('billing.profile.save');
        Route::post('cancel', [$bc, 'cancel'])->middleware('throttle:10,1')->name('billing.cancel');
        Route::post('resume', [$bc, 'resume'])->middleware('throttle:10,1')->name('billing.resume');
        Route::post('downgrade', [$bc, 'downgrade'])->middleware('throttle:10,1')->name('billing.downgrade');
        Route::post('downgrade/clear', [$bc, 'clearDowngrade'])->middleware('throttle:10,1')->name('billing.downgrade.clear');
        Route::get('demo/{order}', [$bc, 'demo'])->where('order', '[0-9a-z]{26}')->name('billing.demo');
    });

    Route::middleware(ResolveTenant::class)->prefix('app/members')->group(function () {
        Route::get('/', [MemberController::class, 'index'])->name('members.index');
        Route::post('/invitations', [InvitationController::class, 'store'])->middleware('throttle:invitations')->name('members.invite');
        Route::delete('/{userId}', [MemberController::class, 'destroy'])->name('members.destroy');
    });

    Route::get('/settings/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->name('profile.show');
    Route::put('/settings/profile', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');

    Route::middleware('password.confirm')->prefix('settings/security')->group(function () {
        Route::get('/', [SecurityController::class, 'show'])->name('security.show');
        Route::delete('/sessions', [SecurityController::class, 'destroyOtherSessions'])->name('security.sessions.others');
        Route::delete('/sessions/{id}', [SecurityController::class, 'destroySession'])->name('security.sessions.destroy');
        Route::delete('/tokens/{id}', [SecurityController::class, 'destroyToken'])->whereNumber('id')->name('security.tokens.destroy');
    });
});
