<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redis_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('redis_cluster_id')->nullable()->constrained('redis_clusters')->nullOnDelete();
            $table->string('name');
            $table->integer('redis_port')->default(6379);
            $table->integer('sentinel_port')->default(26379);
            $table->string('role')->default('pending');
            $table->string('status')->default('unknown');
            $table->boolean('redis_installed')->default(false);
            $table->boolean('sentinel_installed')->default(false);
            $table->boolean('redis_configured')->default(false);
            $table->string('redis_version')->nullable();
            $table->json('last_health_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redis_nodes');
    }
};
