<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft 365 groups for VPN access:
 *  - a company group: its members get VPN access without being added one by one;
 *  - a group per firewall: only its members can use that firewall.
 * vpn_users.source tells people added by hand ("manual") from people who came in through a group ("group").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_companies', function (Blueprint $table) {
            $table->string('access_group_id', 36)->nullable()->after('email_domains');
            $table->string('access_group_name')->nullable()->after('access_group_id');
        });
        Schema::table('vpn_sites', function (Blueprint $table) {
            $table->string('access_group_id', 36)->nullable()->after('allowed_ips');
            $table->string('access_group_name')->nullable()->after('access_group_id');
        });
        Schema::table('vpn_users', function (Blueprint $table) {
            $table->string('source', 10)->default('manual')->after('auth_type');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_companies', fn (Blueprint $t) => $t->dropColumn(['access_group_id', 'access_group_name']));
        Schema::table('vpn_sites', fn (Blueprint $t) => $t->dropColumn(['access_group_id', 'access_group_name']));
        Schema::table('vpn_users', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
