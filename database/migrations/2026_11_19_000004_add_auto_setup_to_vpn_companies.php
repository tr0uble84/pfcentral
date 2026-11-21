<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One-click Microsoft 365 setup: remembers the app it created, its secret's key ID and when the secret expires. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_companies', function (Blueprint $table) {
            $table->string('ms_app_object_id', 36)->nullable()->after('ms_client_secret');
            $table->string('ms_secret_key_id', 36)->nullable()->after('ms_app_object_id');
            $table->timestamp('ms_secret_expires_at')->nullable()->after('ms_secret_key_id');
            $table->timestamp('ms_auto_setup_at')->nullable()->after('ms_secret_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_companies', function (Blueprint $table) {
            $table->dropColumn(['ms_app_object_id', 'ms_secret_key_id', 'ms_secret_expires_at', 'ms_auto_setup_at']);
        });
    }
};
