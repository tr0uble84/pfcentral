<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VPN access groups: a company's group names a set of its VPN firewalls and who may use them —
 * people picked by hand, "everyone with VPN access", and/or groups from a sign-in provider
 * (provider + the provider's group id: Microsoft 365 now, other SSO later).
 * Once a company has a group, its firewalls that are in no group are blocked.
 *
 * The earlier "one Microsoft 365 group per firewall" setting becomes a group per such firewall; the company's
 * other firewalls go into an "Everyone" group so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        // v0.8.5 stopped part-way on MySQL (an index name over 64 characters) after creating the tables, and its
        // code then ran on them. If groups were saved meanwhile, keep everything and only add the missing index.
        if (Schema::hasTable('vpn_access_group_links') && DB::table('vpn_access_groups')->exists()) {
            try {
                Schema::table('vpn_access_group_links', fn (Blueprint $t) => $t->unique(['vpn_access_group_id', 'provider', 'external_id'], 'vpn_ag_links_unique'));
            } catch (\Throwable) {
                // duplicate links: leave the table as it is
            }
            DB::table('vpn_sites')->whereNotNull('access_group_id')->update(['access_group_id' => null, 'access_group_name' => null]);
            return;
        }
        // Otherwise the leftover tables are empty: start clean.
        Schema::dropIfExists('vpn_access_group_links');
        Schema::dropIfExists('vpn_access_group_users');
        Schema::dropIfExists('vpn_access_group_sites');
        Schema::dropIfExists('vpn_access_groups');

        Schema::create('vpn_access_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('everyone')->default(false); // everyone with VPN access in the company is a member
            $table->timestamps();
            $table->unique(['company_id', 'name'], 'vpn_ag_company_name_unique');
        });
        Schema::create('vpn_access_group_sites', function (Blueprint $table) {
            $table->foreignId('vpn_access_group_id')->constrained('vpn_access_groups', 'id', 'vpn_ag_sites_group_fk')->cascadeOnDelete();
            $table->foreignId('vpn_site_id')->constrained('vpn_sites', 'id', 'vpn_ag_sites_site_fk')->cascadeOnDelete();
            $table->primary(['vpn_access_group_id', 'vpn_site_id'], 'vpn_ag_sites_primary');
        });
        Schema::create('vpn_access_group_users', function (Blueprint $table) {
            $table->foreignId('vpn_access_group_id')->constrained('vpn_access_groups', 'id', 'vpn_ag_users_group_fk')->cascadeOnDelete();
            $table->foreignId('vpn_user_id')->constrained('vpn_users', 'id', 'vpn_ag_users_user_fk')->cascadeOnDelete();
            $table->primary(['vpn_access_group_id', 'vpn_user_id'], 'vpn_ag_users_primary');
        });
        Schema::create('vpn_access_group_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vpn_access_group_id')->constrained('vpn_access_groups', 'id', 'vpn_ag_links_group_fk')->cascadeOnDelete();
            $table->string('provider', 20); // "microsoft" (more sign-in providers later)
            $table->string('external_id', 100); // the provider's group id
            $table->string('external_name')->nullable();
            $table->timestamps();
            $table->unique(['vpn_access_group_id', 'provider', 'external_id'], 'vpn_ag_links_unique');
        });

        // Old per-firewall Microsoft 365 groups → access groups.
        $now = now();
        $grouped = DB::table('vpn_sites')->whereNotNull('access_group_id')->get();
        foreach ($grouped->groupBy('company_id') as $companyId => $sites) {
            $names = [];
            foreach ($sites as $s) {
                $base = (string) (DB::table('firewalls')->where('id', $s->firewall_id)->value('name') ?: "Firewall {$s->firewall_id}");
                $name = mb_substr($base, 0, 70);
                for ($i = 2; in_array($name, $names, true); $i++) {
                    $name = mb_substr($base, 0, 66) . " ({$i})";
                }
                $names[] = $name;
                $gid = DB::table('vpn_access_groups')->insertGetId(['company_id' => $companyId, 'name' => $name, 'everyone' => false, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('vpn_access_group_sites')->insert(['vpn_access_group_id' => $gid, 'vpn_site_id' => $s->id]);
                DB::table('vpn_access_group_links')->insert([
                    'vpn_access_group_id' => $gid, 'provider' => 'microsoft', 'external_id' => strtolower($s->access_group_id),
                    'external_name' => $s->access_group_name, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $open = DB::table('vpn_sites')->where('company_id', $companyId)->whereNull('access_group_id')->pluck('id');
            if ($open->isNotEmpty()) {
                $gid = DB::table('vpn_access_groups')->insertGetId(['company_id' => $companyId, 'name' => in_array('Everyone', $names, true) ? 'Everyone with VPN access' : 'Everyone', 'everyone' => true, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('vpn_access_group_sites')->insert($open->map(fn ($id) => ['vpn_access_group_id' => $gid, 'vpn_site_id' => $id])->all());
            }
        }
        DB::table('vpn_sites')->update(['access_group_id' => null, 'access_group_name' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_access_group_links');
        Schema::dropIfExists('vpn_access_group_users');
        Schema::dropIfExists('vpn_access_group_sites');
        Schema::dropIfExists('vpn_access_groups');
    }
};
