<?php

namespace App\Services\Deploy;

use App\Jobs\RunDeploymentTargetJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Firewall;
use App\Models\User;
use App\Services\FirewallApiCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/** The steps of a deployment on one firewall, and the rules for starting waves. */
final class DeployRunner
{
    public static function firewallsFor(User $user): Builder
    {
        $q = Firewall::query()->with('company')->orderBy('name');
        return $user->isGlobalAdmin() ? $q : $q->where('company_id', $user->company_id);
    }

    /**
     * What a pre-check plan means for the firewall.
     *
     * @return array{0: string, 1: ?string} [ok|needs_package|problem, message]
     */
    public static function verdict(array $plan): array
    {
        $needs = collect($plan)->where('status', 'needs_package');
        if ($needs->isNotEmpty()) {
            return ['needs_package', $needs->pluck('messages')->flatten()->unique()->implode(' ')];
        }
        $errors = collect($plan)->where('status', 'error');
        if ($errors->isNotEmpty()) {
            return ['problem', $errors->map(fn ($p) => ($p['label'] ?: 'A change') . ': ' . implode(' ', $p['messages'] ?? []))->implode(' ')];
        }
        $counts = collect($plan)->countBy('status');
        $parts = [];
        foreach (['create' => 'to add', 'update' => 'to update', 'remove' => 'to remove', 'same' => 'already set'] as $k => $word) {
            if ($counts->get($k)) {
                $parts[] = $counts->get($k) . ' ' . $word;
            }
        }
        return ['ok', $parts ? ucfirst(implode(', ', $parts)) . '.' : null];
    }

    /** What to keep from the helper's answer for the pages. */
    public static function facts(array $answer): array
    {
        return [
            'version' => $answer['version'] ?? null,
            'pfblockerng' => $answer['pfblockerng'] ?? null,
            'plan' => array_map(fn ($p) => ['n' => $p['n'] ?? null, 'status' => $p['status'] ?? null, 'target' => $p['target'] ?? null, 'messages' => $p['messages'] ?? []], $answer['plan'] ?? []),
        ];
    }

    public static function label(Deployment $d): string
    {
        return "deployment #{$d->id} {$d->name}";
    }

    /** Pre-check, then deploy. On any problem the firewall is left as it was. */
    public static function deploy(DeploymentTarget $t): void
    {
        $d = $t->deployment;
        $helper = new DeployHelper($t->firewall);
        $t->update(['status' => 'deploying', 'started_at' => now(), 'finished_at' => null, 'message' => 'Checking the firewall…']);
        $plan = [];
        try {
            $check = $helper->check($d->items);
            $plan = $check['plan'] ?? [];
            [$verdict, $message] = self::verdict($plan);
            $t->update(['check' => self::facts($check), 'checked_at' => now()]);
            if ($verdict === 'needs_package') {
                self::finish($t, 'needs_package', $message . ' Skipped; install it and retry.');
                return;
            }
            if ($verdict === 'problem') {
                self::finish($t, 'failed', 'Not deployed, nothing was changed. ' . $message);
                return;
            }

            $t->update(['message' => 'Deploying ' . count($d->items) . ' change(s)…']);
            $answer = $helper->apply($d->items, self::label($d));
            FirewallApiCache::forget($t->firewall_id);
            $changed = (int) ($answer['changed'] ?? 0);
            $msg = $changed === 0 ? 'Everything was already set; nothing changed.' : "Deployed: {$changed} change(s).";
            if (!empty($answer['pfb_update'])) {
                $msg .= ' pfBlockerNG is updating its lists.';
            }
            if (!empty($answer['note'])) {
                $msg .= ' ' . $answer['note'];
            }
            $t->update(['undo' => $answer['undo'] ?? [], 'changed' => $changed, 'result' => self::facts($answer)['plan']]);
            self::finish($t, 'done', $msg);
            Log::info('Deployment: firewall done', ['deployment' => $d->id, 'firewall' => $t->firewall_id, 'changed' => $changed]);
        } catch (DeployFailed $e) {
            FirewallApiCache::forget($t->firewall_id);
            if (!empty($e->answer['undone'])) {
                self::finish($t, 'undone', 'Deployed, but the firewall couldn\'t load the new rules, so the change was undone automatically. ' . $e->getMessage());
            } else {
                self::finish($t, 'failed', 'Not deployed, nothing was changed. ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            FirewallApiCache::forget($t->firewall_id);
            self::lostAnswer($t, $helper, $plan, $e);
        }
    }

    /**
     * The firewall's answer to "apply" never arrived. The helper saves everything at once, so it's either all there
     * or not at all: look again and say which.
     */
    private static function lostAnswer(DeploymentTarget $t, DeployHelper $helper, array $plan, \Throwable $e): void
    {
        if (!$plan) {
            self::finish($t, 'failed', 'Couldn\'t reach the firewall; nothing was changed. ' . $e->getMessage());
            return;
        }
        try {
            $after = $helper->check($t->deployment->items)['plan'] ?? [];
        } catch (\Throwable) {
            self::finish($t, 'failed', 'The firewall stopped answering during the deployment (' . $e->getMessage() . '). Check it, then retry or undo.');
            return;
        }
        $applied = collect($after)->every(fn ($p) => ($p['status'] ?? '') === 'same');
        if (!$applied) {
            self::finish($t, 'failed', 'The firewall didn\'t answer and the changes aren\'t on it; nothing was changed. ' . $e->getMessage());
            return;
        }
        $t->update(['undo' => [], 'changed' => collect($plan)->whereIn('status', ['create', 'update'])->count()]);
        self::finish($t, 'done', 'Deployed. The firewall\'s answer was lost, so Undo isn\'t available for this firewall; remove the changes on the firewall if needed.');
    }

    public static function undo(DeploymentTarget $t): void
    {
        try {
            $answer = (new DeployHelper($t->firewall))->undo($t->undo ?? [], self::label($t->deployment));
            FirewallApiCache::forget($t->firewall_id);
            $n = (int) ($answer['undone'] ?? 0);
            self::finish($t, 'reverted', $n ? "Undone: {$n} change(s) taken off the firewall." : ($answer['message'] ?? 'Nothing was left to undo.'));
        } catch (\Throwable $e) {
            FirewallApiCache::forget($t->firewall_id);
            self::finish($t, 'failed', 'Undo didn\'t work: ' . $e->getMessage());
        }
    }

    private static function finish(DeploymentTarget $t, string $status, ?string $message): void
    {
        $t->update(['status' => $status, 'message' => $message, 'finished_at' => now()]);
        $t->deployment->refresh()->refreshStatus();
    }

    /** @return array{0: bool, 1: ?string} */
    public static function canStartWave(Deployment $d, int $wave): array
    {
        if ($d->status === 'stopped') {
            return [false, 'This deployment was stopped.'];
        }
        $targets = $d->targets;
        if ($targets->whereIn('status', ['pending', 'checking'])->isNotEmpty()) {
            return [false, 'Wait for the pre-check to finish.'];
        }
        $inWave = $targets->where('wave', $wave);
        if ($inWave->whereIn('status', ['ready', 'problem'])->isEmpty() && $inWave->where('status', 'needs_package')->whereNull('started_at')->isEmpty()) {
            return [false, 'Nothing to deploy in wave ' . $wave . '.'];
        }
        $earlier = $targets->where('wave', '<', $wave);
        if ($earlier->contains(fn ($t) => !in_array($t->status, DeploymentTarget::FINISHED, true))) {
            return [false, 'Every firewall in the earlier waves must finish well first.'];
        }
        return [true, null];
    }

    /** Starts a wave: every firewall in it that hasn't been deployed (each is checked again first). */
    public static function startWave(Deployment $d, int $wave, string $by): int
    {
        $d->load('targets');
        // Firewalls that needed a package at the pre-check are checked again too: it may have been installed since.
        $targets = $d->targets->where('wave', $wave)->filter(fn ($t) => in_array($t->status, ['ready', 'problem'], true) || ($t->status === 'needs_package' && !$t->started_at));
        $d->update(['status' => 'running', 'current_wave' => $wave, 'scheduled_at' => null, 'scheduled_wave' => null, 'scheduled_by' => null]);
        foreach ($targets as $t) {
            $t->update(['status' => 'queued', 'message' => 'Waiting to start…']);
        }
        Log::info('Deployment: wave started', ['deployment' => $d->id, 'wave' => $wave, 'by' => $by, 'firewalls' => $targets->pluck('firewall_id')->all()]);
        foreach ($targets as $t) {
            RunDeploymentTargetJob::dispatch($t->id);
        }
        $d->refresh()->refreshStatus();
        return $targets->count();
    }

    /** Scheduler: start waves whose time has come. */
    public static function runDue(): void
    {
        Deployment::whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->whereNotIn('status', ['stopped', 'completed'])->get()
            ->each(function (Deployment $d) {
                $d->load('targets');
                $wave = (int) $d->scheduled_wave;
                [$ok, $why] = self::canStartWave($d, $wave);
                if ($ok) {
                    self::startWave($d, $wave, 'schedule (' . ($d->scheduled_by ?? 'unknown') . ')');
                } else {
                    Log::warning('Deployment: scheduled wave not started', ['deployment' => $d->id, 'wave' => $wave, 'why' => $why]);
                    $d->update(['scheduled_at' => null, 'scheduled_wave' => null]);
                    $d->targets->where('wave', $wave)->whereIn('status', ['ready', 'problem'])
                        ->each(fn ($t) => $t->update(['message' => trim('Scheduled start didn\'t run: ' . $why)]));
                }
            });
    }
}
