<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_private_key_encrypted')->nullable();
            $table->text('ssh_public_key')->nullable();
            $table->string('ssh_key_fingerprint')->nullable();
            $table->unsignedInteger('ram_mb')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            $table->string('os_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
