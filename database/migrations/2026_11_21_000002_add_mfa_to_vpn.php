<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor sign-in (authenticator app, e.g. Google Authenticator) for email-and-password VPN accounts.
 * vpn_companies.mfa_mode: optional | required.  vpn_users.mfa_mode: null (company setting) | optional | required.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vpn_companies', 'mfa_mode')) {
            Schema::table('vpn_companies', fn (Blueprint $t) => $t->string('mfa_mode', 10)->default('optional'));
        }
        if (!Schema::hasColumn('vpn_users', 'mfa_secret')) {
            Schema::table('vpn_users', function (Blueprint $t) {
                $t->string('mfa_mode', 10)->nullable();
                $t->text('mfa_secret')->nullable();          // encrypted
                $t->timestamp('mfa_enabled_at')->nullable();
                $t->text('mfa_recovery')->nullable();        // encrypted list of hashed one-time recovery codes
                $t->unsignedBigInteger('mfa_last_step')->nullable(); // last accepted time step: a code works once
            });
        }
    }

    public function down(): void
    {
        Schema::table('vpn_users', fn (Blueprint $t) => $t->dropColumn(['mfa_mode', 'mfa_secret', 'mfa_enabled_at', 'mfa_recovery', 'mfa_last_step']));
        Schema::table('vpn_companies', fn (Blueprint $t) => $t->dropColumn('mfa_mode'));
    }
};
