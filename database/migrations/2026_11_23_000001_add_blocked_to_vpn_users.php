<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Block access" on the live VPN page: the person's devices are removed and they can't sign in until unblocked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('last_login_at');
            $table->string('blocked_by')->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_by']);
        });
    }
};
