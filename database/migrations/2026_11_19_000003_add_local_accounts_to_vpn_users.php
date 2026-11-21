<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VPN accounts with an email and password, for companies without Microsoft Entra.
 * The person sets the password through an emailed invitation link; only a hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->string('ms_oid', 36)->nullable()->change();
            $table->string('auth_type', 16)->default('microsoft')->after('company_id'); // microsoft | local
            $table->string('password')->nullable();
            $table->string('invite_token_hash', 64)->nullable()->index();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropColumn(['auth_type', 'password', 'invite_token_hash', 'invite_expires_at', 'invited_at', 'password_set_at', 'failed_logins', 'locked_until']);
        });
    }
};
