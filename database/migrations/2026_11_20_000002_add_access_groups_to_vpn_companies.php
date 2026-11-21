<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A company can use several Microsoft 365 groups that give VPN access (was one: access_group_id/name).
 * access_groups = [{"id": "<group id>", "name": "<group name>"}, …]
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vpn_companies', 'access_groups')) {
            Schema::table('vpn_companies', fn (Blueprint $t) => $t->json('access_groups')->nullable()->after('access_group_name'));
        }
        foreach (DB::table('vpn_companies')->whereNotNull('access_group_id')->get() as $vc) {
            DB::table('vpn_companies')->where('id', $vc->id)->update([
                'access_groups' => json_encode([['id' => strtolower($vc->access_group_id), 'name' => (string) $vc->access_group_name]]),
                'access_group_id' => null,
                'access_group_name' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('vpn_companies', fn (Blueprint $t) => $t->dropColumn('access_groups'));
    }
};
