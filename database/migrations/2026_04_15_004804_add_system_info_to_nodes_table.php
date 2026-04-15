<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('ram_mb')->nullable()->after('mysql_version');
            $table->unsignedSmallInteger('cpu_cores')->nullable()->after('ram_mb');
            $table->string('os_name')->nullable()->after('cpu_cores');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['ram_mb', 'cpu_cores', 'os_name']);
        });
    }
};
