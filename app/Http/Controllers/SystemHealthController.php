<?php

namespace App\Http\Controllers;

use App\Services\Health\ServerHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/** System → Server health (global admins): the PFCentral server itself, not the firewalls. */
class SystemHealthController extends Controller
{
    public function show()
    {
        $checks = ServerHealth::checks();

        return view('system.health', [
            'checks' => $checks,
            'overall' => ServerHealth::overall($checks),
            'maintenance' => ServerHealth::maintenanceStatus(),
            'installCmd' => 'sudo bash ' . base_path('scripts/install-maintenance.sh'),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    public function data()
    {
        $checks = ServerHealth::checks();

        return response()->json(['checks' => $checks, 'overall' => ServerHealth::overall($checks), 'checkedAt' => now()->toIso8601String()]);
    }

    /** Runs the root maintenance script (allowed through one sudo rule that install-maintenance.sh adds). */
    public function runMaintenance()
    {
        $script = '/usr/local/sbin/pfcentral-maintenance.sh';
        if (! is_file($script)) {
            return response()->json(['message' => 'The maintenance timer is not installed yet. Run on the server: sudo bash ' . base_path('scripts/install-maintenance.sh')], 422);
        }
        $r = Process::timeout(240)->run(['sudo', '-n', $script]);
        Cache::store('file')->forget('pfc:health:banner');
        if (! $r->successful()) {
            $err = trim($r->errorOutput()) ?: trim($r->output());

            return response()->json(['message' => str_contains($err, 'password') || str_contains($err, 'sudo')
                ? 'PFCentral is not allowed to start it yet. Run once on the server: sudo bash ' . base_path('scripts/install-maintenance.sh')
                : 'The maintenance run failed: ' . mb_substr($err, 0, 300)], 422);
        }

        return response()->json(['message' => 'Maintenance finished.', 'status' => ServerHealth::maintenanceStatus()]);
    }

    /** Removes expired cache rows, old failed jobs, expired sessions and old dated logs. */
    public function runCleanup()
    {
        Artisan::call('pfcentral:cleanup');
        Cache::store('file')->forget('pfc:health:banner');

        return response()->json(['message' => trim(Artisan::output()) ?: 'Cleanup finished.']);
    }
}
