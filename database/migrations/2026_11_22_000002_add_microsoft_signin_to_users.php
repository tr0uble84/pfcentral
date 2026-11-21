<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Microsoft 365 object id of MSP staff who sign in with Microsoft (unique per person).
            $table->string('ms_oid', 64)->nullable()->unique('users_ms_oid_unique');
            $table->string('ms_upn')->nullable();
            // Last release whose "What's new" window this person has closed.
            $table->string('whats_new_seen', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_ms_oid_unique');
            $table->dropColumn(['ms_oid', 'ms_upn', 'whats_new_seen']);
        });
    }
};
