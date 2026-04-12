<?php

use App\Livewire\AuditLogViewer;
use App\Livewire\ClusterManager;
use App\Livewire\ClusterSetupWizard;
use App\Livewire\Dashboard;
use App\Livewire\NodeLogs;
use App\Livewire\RouterManager;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
    Route::get('cluster/create', ClusterSetupWizard::class)->name('cluster.create');
    Route::get('cluster/{cluster}', ClusterManager::class)->name('cluster.manage');
    Route::get('cluster/{cluster}/routers', RouterManager::class)->name('cluster.routers');
    Route::get('node/{node}/logs', NodeLogs::class)->name('node.logs');
    Route::get('audit-logs', AuditLogViewer::class)->name('audit-logs');
});

require __DIR__.'/settings.php';
