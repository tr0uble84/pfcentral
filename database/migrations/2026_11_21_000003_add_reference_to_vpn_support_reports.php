<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Support tickets get a random 5-digit reference (VPN-48213) instead of the row number. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vpn_support_reports', 'reference')) {
            Schema::table('vpn_support_reports', function (Blueprint $t) {
                $t->string('reference', 16)->nullable()->after('id');
                $t->unique('reference', 'vpn_sr_reference_unique');
            });
        }
        foreach (DB::table('vpn_support_reports')->whereNull('reference')->pluck('id') as $id) {
            DB::table('vpn_support_reports')->where('id', $id)->update(['reference' => 'VPN-' . $id]);
        }
    }

    public function down(): void
    {
        Schema::table('vpn_support_reports', function (Blueprint $t) {
            $t->dropUnique('vpn_sr_reference_unique');
            $t->dropColumn('reference');
        });
    }
};
