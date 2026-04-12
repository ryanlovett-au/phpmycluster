<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('communication_stack')->default('MYSQL'); // MYSQL or XCOM
            $table->string('ip_allowlist')->nullable(); // dynamically built from node IPs
            $table->string('cluster_admin_user')->default('clusteradmin');
            $table->text('cluster_admin_password_encrypted')->nullable();
            $table->string('status')->default('pending'); // pending, online, degraded, offline, error
            $table->json('last_status_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
