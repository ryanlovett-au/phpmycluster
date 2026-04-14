<?php

use App\Livewire\AuditLogViewer;
use App\Livewire\ClusterManager;
use App\Livewire\ClusterSetupWizard;
use App\Livewire\Dashboard;
use App\Livewire\NodeLogs;
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
    Route::get('cluster/create', ClusterSetupWizard::class)->name('cluster.create');
    Route::get('cluster/{cluster}/reprovision', ClusterSetupWizard::class)->name('cluster.reprovision');
    Route::get('cluster/{cluster}', ClusterManager::class)->name('cluster.manage');
    Route::get('cluster/{cluster}/routers', RouterManager::class)->name('cluster.routers');
    Route::get('node/{node}/logs', NodeLogs::class)->name('node.logs');
    Route::get('audit-logs', AuditLogViewer::class)->name('audit-logs');
    Route::get('users', UserApproval::class)->name('users.index');
});

require __DIR__.'/settings.php';
