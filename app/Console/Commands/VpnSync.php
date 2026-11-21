<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Vpn\VpnCompany;
use App\Models\Vpn\VpnSite;
use App\Services\Vpn\VpnManager;
use Illuminate\Console\Command;

/** Remove VPN devices of people who lost access (removed, disabled or deleted in Microsoft 365). */
class VpnSync extends Command
{
    protected $signature = 'vpn:sync';
    protected $description = 'Remove WireGuard devices of people who no longer have VPN access';

    public function handle(VpnManager $vpn): int
    {
        $ids = VpnCompany::whereNotNull('ms_tenant_id')->pluck('company_id')->merge(VpnSite::pluck('company_id'))->unique();
        $total = 0;
        foreach (Company::whereIn('id', $ids)->get() as $company) {
            try {
                $r = $vpn->sync($company);
                $total += $r['removed'];
                $this->line("{$company->name}: {$r['removed']} removed" . ($r['skipped'] ? ' (Microsoft 365 unavailable: account check skipped)' : ''));
            } catch (\Throwable $e) {
                $this->error("{$company->name}: {$e->getMessage()}");
            }
        }
        $this->info("VPN sync done: {$total} device(s) removed.");
        return self::SUCCESS;
    }
}
