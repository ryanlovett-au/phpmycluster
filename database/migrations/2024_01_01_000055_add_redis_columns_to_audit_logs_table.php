<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('redis_cluster_id')->nullable()->after('node_id')->constrained('redis_clusters')->nullOnDelete();
            $table->foreignId('redis_node_id')->nullable()->after('redis_cluster_id')->constrained('redis_nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('redis_node_id');
            $table->dropConstrainedForeignId('redis_cluster_id');
        });
    }
};
