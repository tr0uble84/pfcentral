<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Firewall;
use App\Jobs\CheckFirewallStatusJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backend firewall status scheduler.
//
// Dispatches CheckFirewallStatusJob for every firewall every minute,
// decoupling status production from browser activity.
//
// Protection layers:
//   withoutOverlapping(120) — prevents concurrent dispatch closures (edge case).
//   ShouldBeUnique on job  — prevents duplicate pending queue entries per firewall.
//   Cache::lock in handle()— prevents concurrent pfSense API calls per firewall.
//
// Burst: N LPUSH operations at minute boundary (~0.1ms each, negligible).
// Workers drain N api-call jobs in seconds. Safe for fleets under ~400 firewalls.
//
// Firewall::all() is correct here — the scheduler has no user context.
// Private channel authorization ensures subscribers only receive updates
// for firewalls they are permitted to see.
Schedule::call(function () {
    Firewall::all()->each(fn (Firewall $fw) => CheckFirewallStatusJob::dispatch($fw));
})->everyMinute()
  ->name('firewall-status-poll')
  ->withoutOverlapping(120);

Schedule::command('firewalls:backup-stale-configs')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

// VPN self-service: drop WireGuard devices of people who lost access (every 15 minutes).
Schedule::command('vpn:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer();

// Upgrade centre: re-run the pre-checks and "newer version?" check of every pfSense firewall nightly.
Schedule::call(function () {
    Firewall::all()->reject(fn (Firewall $fw) => $fw->isOpnSense())->each(fn (Firewall $fw) => \App\Jobs\CheckFirmwareJob::dispatch($fw->id));
})->dailyAt('03:30')
  ->name('firmware-checks')
  ->withoutOverlapping(60)
  ->onOneServer();

// Firewall web interfaces through PFCentral: end idle sessions and remove their temporary firewall accounts.
Schedule::call(fn () => \App\Services\Security\FirewallGui::cleanUp())
    ->everyFiveMinutes()
    ->name('firewall-gui-cleanup')
    ->withoutOverlapping(10)
    ->onOneServer();

// Deployments: start waves scheduled for now.
Schedule::call(fn () => \App\Services\Deploy\DeployRunner::runDue())
    ->everyMinute()
    ->name('deployments-due')
    ->withoutOverlapping(5)
    ->onOneServer();

// Server health: the scheduler and the queue worker check in, so System → Server health
// can tell when either has stopped.
Schedule::call(fn () => \App\Services\Health\ServerHealth::beatScheduler())
    ->everyMinute()
    ->name('health-scheduler-beat');

Schedule::job(new \App\Jobs\QueueHeartbeatJob())
    ->everyFiveMinutes()
    ->name('health-queue-beat');

// Server health: prune old failed jobs, expired cache rows and sessions, and old dated logs.
Schedule::command('pfcentral:cleanup')
    ->dailyAt('03:45')
    ->withoutOverlapping(60)
    ->onOneServer();
