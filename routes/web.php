<?php

use App\Livewire\AuditLogViewer;
use App\Livewire\ClusterManager;
use App\Livewire\ClusterSetupWizard;
use App\Livewire\Dashboard;
use App\Livewire\NodeLogs;
use App\Livewire\RedisClusterManager;
use App\Livewire\RedisNodeLogs;
use App\Livewire\RedisSetupWizard;
use App\Livewire\RouterManager;
use App\Livewire\UserApproval;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Pending approval page (authenticated but not yet approved)
Route::middleware(['auth'])->group(function () {
    Route::get('approval/pending', function () {
        if (auth()->user()->isApproved()) {
            return redirect()->route('dashboard');
        }

        return view('pages.approval-pending');
    })->name('approval.pending');
});

// All app routes require auth + approval
Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    // MySQL routes
    Route::get('mysql/create', ClusterSetupWizard::class)->name('mysql.create');
    Route::get('mysql/{cluster}/reprovision', ClusterSetupWizard::class)->name('mysql.reprovision');
    Route::get('mysql/{cluster}', ClusterManager::class)->name('mysql.manage');
    Route::get('mysql/{cluster}/routers', RouterManager::class)->name('mysql.routers');
    Route::get('node/{node}/logs', NodeLogs::class)->name('node.logs');

    // Redis routes
    Route::get('redis/create', RedisSetupWizard::class)->name('redis.create');
    Route::get('redis/{cluster}/reprovision', RedisSetupWizard::class)->name('redis.reprovision');
    Route::get('redis/{cluster}', RedisClusterManager::class)->name('redis.manage');
    Route::get('redis/node/{node}/logs', RedisNodeLogs::class)->name('redis.node.logs');

    Route::get('audit-logs', AuditLogViewer::class)->name('audit-logs');
    Route::get('users', UserApproval::class)->name('users.index');
});

require __DIR__.'/settings.php';
