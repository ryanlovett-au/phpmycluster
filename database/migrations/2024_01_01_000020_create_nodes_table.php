<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('host'); // IP or hostname
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_private_key_encrypted')->nullable();
            $table->text('ssh_public_key')->nullable();
            $table->string('ssh_key_fingerprint')->nullable();
            $table->integer('mysql_port')->default(3306);
            $table->integer('mysql_x_port')->default(33060);
            $table->string('role')->default('pending'); // pending, primary, secondary, access (router node)
            $table->string('status')->default('unknown'); // unknown, online, recovering, offline, error, unreachable
            $table->integer('server_id')->nullable(); // unique server-id for MySQL config
            $table->boolean('mysql_installed')->default(false);
            $table->boolean('mysql_shell_installed')->default(false);
            $table->boolean('mysql_router_installed')->default(false);
            $table->boolean('mysql_configured')->default(false);
            $table->string('mysql_version')->nullable();
            $table->json('last_health_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
