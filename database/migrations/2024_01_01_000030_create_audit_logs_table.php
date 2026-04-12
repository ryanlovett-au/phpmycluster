<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // e.g. 'cluster.create', 'node.add', 'node.remove', 'firewall.open'
            $table->string('status')->default('started'); // started, success, failed
            $table->text('command')->nullable(); // the command that was run (sanitised)
            $table->longText('output')->nullable(); // stdout/stderr
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
