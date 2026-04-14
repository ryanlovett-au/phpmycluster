<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->string('mysql_version')->nullable()->after('communication_stack');
            $table->string('mysql_apt_config_version')->nullable()->after('mysql_version');
        });
    }

    public function down(): void
    {
        Schema::table('clusters', function (Blueprint $table) {
            $table->dropColumn(['mysql_version', 'mysql_apt_config_version']);
        });
    }
};
