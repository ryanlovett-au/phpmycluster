<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redis_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('redis_version')->nullable();
            $table->string('auth_password_encrypted')->nullable();
            $table->string('sentinel_password_encrypted')->nullable();
            $table->integer('quorum')->default(2);
            $table->integer('down_after_milliseconds')->default(5000);
            $table->integer('failover_timeout')->default(60000);
            $table->string('status')->default('pending');
            $table->json('last_status_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redis_clusters');
    }
};
