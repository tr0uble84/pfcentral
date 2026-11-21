<?php

namespace App\Http\Controllers;

use App\Jobs\CheckDeploymentTargetJob;
use App\Jobs\RunDeploymentTargetJob;
use App\Jobs\UndoDeploymentTargetJob;
use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\Firewall;
use App\Services\Auth\MspSignIn;
use App\Services\Deploy\DeployAccess;
use App\Services\Deploy\DeployHelper;
use App\Services\Deploy\DeployItems;
use App\Services\Deploy\DeployRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Deployments: the same rules, aliases, port forwards or pfBlockerNG lists on many firewalls. Every firewall is
 * pre-checked (packages, interfaces, what would be added or updated), changes go out in waves started by hand or at a
 * scheduled time, and each firewall can be undone.
 */
class DeploymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $deployments = Deployment::withCount([
            'targets',
            'targets as done_count' => fn ($q) => $q->where('status', 'done'),
            'targets as attention_count' => fn ($q) => $q->whereIn('status', DeploymentTarget::NEEDS_ATTENTION),
            'targets as package_count' => fn ($q) => $q->where('status', 'needs_package'),
        ])->orderByDesc('id')->limit(200)->get()->filter(fn ($d) => $d->visibleTo($user))->take(50)->values();

        return view('deployments.index', [
            'deployments' => $deployments,
            'why' => DeployAccess::why($user),
            'access' => DeployAccess::get(),
            'manageAccess' => $user->isGlobalAdmin() && !$user->isReadOnly(),
            'canPick' => DeployAccess::canPick(),
        ]);
    }

    public function create(Request $request)
    {
        $this->mayDeploy($request);
        $user = $request->user();
        $copy = null;
        if ($request->filled('from')) {
            $from = Deployment::find((int) $request->input('from'));
            if ($from && $from->visibleTo($user)) {
                $copy = $from;
            }
        }
        $selected = array_map('intval', (array) $request->input('firewall_ids', $copy ? $copy->targets()->pluck('firewall_id')->all() : []));
        return view('deployments.form', [
            'deployment' => null,
            'name' => $copy ? $copy->name . ' (again)' : '',
            'items' => $copy ? $copy->items : [],
            'firewalls' => $this->pickable($request),
            'selected' => $selected,
            'startKind' => in_array($request->input('kind'), array_keys(DeployItems::KINDS), true) ? $request->input('kind') : null,
        ]);
    }

    public function edit(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        abort_unless($deployment->isDraft(), 409, 'This deployment has started; make a new one from it instead.');
        return view('deployments.form', [
            'deployment' => $deployment,
            'name' => $deployment->name,
            'items' => $deployment->items,
            'firewalls' => $this->pickable($request),
            'selected' => $deployment->targets()->pluck('firewall_id')->all(),
            'startKind' => null,
        ]);
    }

    public function store(Request $request)
    {
        $this->mayDeploy($request);
        return $this->save($request, null);
    }

    public function update(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        abort_unless($deployment->isDraft(), 409, 'This deployment has started; make a new one from it instead.');
        return $this->save($request, $deployment);
    }

    private function save(Request $request, ?Deployment $deployment)
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'firewall_ids' => ['required', 'array', 'min:1', 'max:500'],
            'firewall_ids.*' => ['integer'],
            'items' => ['required', 'string', 'max:2000000'],
        ], ['firewall_ids.required' => 'Pick at least one firewall.']);
        $user = $request->user();
        [$items, $errors] = DeployItems::normalize((array) json_decode($request->input('items'), true));
        if ($errors) {
            return back()->withErrors(['items' => $errors])->withInput();
        }
        $order = array_map('intval', $request->input('firewall_ids'));
        $firewalls = DeployRunner::firewallsFor($user)->whereIn('id', $order)->get()
            ->sortBy(fn ($f) => array_search($f->id, $order, true))->values();
        $opn = $firewalls->filter(fn (Firewall $f) => $f->isOpnSense());
        $firewalls = $firewalls->reject(fn (Firewall $f) => $f->isOpnSense())->values();
        if ($firewalls->isEmpty()) {
            return back()->withErrors(['firewall_ids' => 'Pick at least one pfSense firewall. OPNsense isn\'t supported for deployments yet.'])->withInput();
        }
        $companies = $firewalls->pluck('company_id')->unique();
        $name = $request->input('name') ?: (count($items) === 1 ? $items[0]['label'] : count($items) . ' changes') . ' – ' . now()->format('M j');
        $attrs = ['name' => mb_substr($name, 0, 120), 'items' => $items, 'status' => 'draft',
            'company_id' => $user->isGlobalAdmin() ? ($companies->count() === 1 ? $companies->first() : null) : $user->company_id];
        if ($deployment) {
            $deployment->update($attrs + ['scheduled_at' => null, 'scheduled_wave' => null]);
            $deployment->targets()->delete();
        } else {
            $deployment = Deployment::create($attrs + ['created_by' => $user->email]);
        }
        foreach ($firewalls as $i => $fw) {
            $deployment->targets()->create(['firewall_id' => $fw->id, 'wave' => $i === 0 || $firewalls->count() === 1 ? 1 : 2, 'status' => 'checking', 'message' => 'Checking…']);
        }
        Log::info('Deployment saved', ['deployment' => $deployment->id, 'by' => $user->email, 'firewalls' => $firewalls->pluck('id')->all(), 'items' => count($items)]);
        foreach ($deployment->targets()->get() as $t) {
            CheckDeploymentTargetJob::dispatch($t->id);
        }
        $deployment->refresh()->refreshStatus();
        $note = $opn->isNotEmpty() ? 'Left out (OPNsense isn\'t supported yet): ' . $opn->pluck('name')->implode(', ') . '.' : null;
        return redirect()->route('deployments.show', $deployment)
            ->with($note ? 'warning' : 'success', $note ?? 'Checking each firewall. Nothing is changed until you start a wave.');
    }

    public function show(Request $request, Deployment $deployment)
    {
        $this->visible($request, $deployment);
        return view('deployments.show', ['deployment' => $deployment, 'data' => $this->json($request, $deployment)]);
    }

    public function data(Request $request, Deployment $deployment)
    {
        $this->visible($request, $deployment);
        return response()->json($this->json($request, $deployment));
    }

    private function json(Request $request, Deployment $d): array
    {
        $d->load(['targets.firewall.company']);
        $waves = [];
        foreach ($d->waves() as $w) {
            [$ok, $why] = DeployRunner::canStartWave($d, $w);
            $waves[] = ['wave' => $w, 'canStart' => $ok, 'why' => $why];
        }
        return [
            'id' => $d->id,
            'name' => $d->name,
            'status' => $d->status,
            'draft' => $d->isDraft(),
            'createdBy' => $d->created_by,
            'createdAt' => $d->created_at?->toIso8601String(),
            'scheduledAt' => $d->scheduled_at?->toIso8601String(),
            'scheduledWave' => $d->scheduled_wave,
            'scheduledBy' => $d->scheduled_by,
            'readOnly' => !DeployAccess::allows($request->user()),
            'why' => DeployAccess::why($request->user()),
            'packages' => DeployItems::packages($d->items),
            'items' => array_map(fn ($i) => ['kind' => $i['kind'], 'kindLabel' => DeployItems::KINDS[$i['kind']] ?? $i['kind'], 'label' => $i['label'], 'remove' => ($i['mode'] ?? 'present') === 'remove', 'internal' => ($i['interface'] ?? null) === '@internal'], $d->items),
            'waves' => $waves,
            'targets' => $d->targets->sortBy([['wave', 'asc'], ['id', 'asc']])->values()->map(fn (DeploymentTarget $t) => [
                'id' => $t->id,
                'firewall' => $t->firewall?->name ?? 'Removed firewall',
                'firewallUrl' => $t->firewall ? route('firewall.dashboard', $t->firewall) : null,
                'company' => $t->firewall?->company?->name ?? '—',
                'wave' => $t->wave,
                'status' => $t->status,
                'message' => $t->message,
                'version' => $t->check['version'] ?? null,
                'pfb' => $t->check['pfblockerng'] ?? null,
                'plan' => $t->check['plan'] ?? [],
                'result' => $t->result ?? [],
                'changed' => $t->changed,
                'canUndo' => $t->status === 'done' && !empty($t->undo),
                'checkedAt' => $t->checked_at?->toIso8601String(),
                'startedAt' => $t->started_at?->toIso8601String(),
                'finishedAt' => $t->finished_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function recheck(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        abort_unless($deployment->isDraft(), 409, 'Pre-checks run again by themselves when each firewall is deployed.');
        $targets = $deployment->targets()->get();
        foreach ($targets as $t) {
            $t->update(['status' => 'checking', 'message' => 'Checking…']);
        }
        foreach ($targets as $t) {
            CheckDeploymentTargetJob::dispatch($t->id);
        }
        $deployment->refresh()->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function setWave(Request $request, Deployment $deployment, DeploymentTarget $target)
    {
        $this->mayDeploy($request);
        $this->target($request, $deployment, $target);
        $request->validate(['wave' => ['required', 'integer', 'min:1', 'max:20']]);
        abort_unless(in_array($target->status, DeploymentTarget::NOT_STARTED, true), 409, 'This firewall has already been deployed.');
        $target->update(['wave' => (int) $request->input('wave')]);
        return response()->json(['ok' => true]);
    }

    public function removeTarget(Request $request, Deployment $deployment, DeploymentTarget $target)
    {
        $this->mayDeploy($request);
        $this->target($request, $deployment, $target);
        abort_unless(in_array($target->status, DeploymentTarget::NOT_STARTED, true), 409, 'This firewall has already been deployed.');
        abort_if($deployment->targets()->count() <= 1, 409, 'A deployment needs at least one firewall.');
        $target->delete();
        $deployment->refresh()->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function startWave(Request $request, Deployment $deployment, int $wave)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        $deployment->load('targets');
        [$ok, $why] = DeployRunner::canStartWave($deployment, $wave);
        abort_unless($ok, 409, $why ?? 'Nothing to start in this wave.');
        $n = DeployRunner::startWave($deployment, $wave, $request->user()->email);
        return response()->json(['ok' => true, 'message' => "Wave {$wave} started: {$n} firewall(s)."]);
    }

    public function schedule(Request $request, Deployment $deployment, int $wave)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        $request->validate(['at' => ['required', 'date'], 'tz' => ['nullable', 'timezone']]);
        $at = Carbon::parse($request->input('at'), $request->input('tz') ?: config('app.timezone'))->utc();
        abort_if($at->lt(now()->subMinute()), 422, 'Pick a time in the future.');
        abort_if($at->gt(now()->addDays(60)), 422, 'Pick a time within 60 days.');
        $deployment->load('targets');
        abort_if($deployment->status === 'stopped', 409, 'This deployment was stopped.');
        abort_unless($deployment->targets->where('wave', $wave)->filter(fn ($t) => in_array($t->status, ['ready', 'problem', 'pending', 'checking'], true) || ($t->status === 'needs_package' && !$t->started_at))->isNotEmpty(), 409, 'Nothing to deploy in wave ' . $wave . '.');
        $deployment->update(['scheduled_at' => $at, 'scheduled_wave' => $wave, 'scheduled_by' => $request->user()->email]);
        Log::info('Deployment: wave scheduled', ['deployment' => $deployment->id, 'wave' => $wave, 'at' => $at->toIso8601String(), 'by' => $request->user()->email]);
        return response()->json(['ok' => true]);
    }

    public function unschedule(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        $deployment->update(['scheduled_at' => null, 'scheduled_wave' => null, 'scheduled_by' => null]);
        return response()->json(['ok' => true]);
    }

    public function retry(Request $request, Deployment $deployment, DeploymentTarget $target)
    {
        $this->mayDeploy($request);
        $this->target($request, $deployment, $target);
        abort_if($deployment->status === 'stopped', 409, 'This deployment was stopped.');
        if ($deployment->isDraft()) {
            $target->update(['status' => 'checking', 'message' => 'Checking…']);
            CheckDeploymentTargetJob::dispatch($target->id);
        } else {
            abort_unless(in_array($target->status, ['failed', 'undone', 'needs_package', 'problem', 'skipped'], true), 409, 'Only a firewall that wasn\'t deployed can be retried.');
            $target->update(['status' => 'queued', 'message' => 'Trying again…', 'started_at' => null, 'finished_at' => null]);
            RunDeploymentTargetJob::dispatch($target->id);
        }
        $deployment->refresh()->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function skip(Request $request, Deployment $deployment, DeploymentTarget $target)
    {
        $this->mayDeploy($request);
        $this->target($request, $deployment, $target);
        abort_unless(in_array($target->status, ['ready', 'problem', 'failed', 'undone', 'needs_package'], true), 409, 'This firewall can\'t be skipped now.');
        $target->update(['status' => 'skipped', 'message' => trim(($target->message ? $target->message . ' ' : '') . 'Skipped by ' . $request->user()->email . '.'), 'finished_at' => now()]);
        $deployment->refresh()->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function undo(Request $request, Deployment $deployment, DeploymentTarget $target)
    {
        $this->mayDeploy($request);
        $this->target($request, $deployment, $target);
        abort_unless($target->status === 'done' && !empty($target->undo), 409, 'There is nothing to undo on this firewall.');
        $this->queueUndo($deployment, collect([$target]), $request->user()->email);
        return response()->json(['ok' => true]);
    }

    public function undoAll(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        $targets = $deployment->targets()->get()->filter(fn ($t) => $t->status === 'done' && !empty($t->undo));
        abort_if($targets->isEmpty(), 409, 'There is nothing to undo.');
        $this->queueUndo($deployment, $targets, $request->user()->email);
        return response()->json(['ok' => true, 'message' => 'Undoing on ' . $targets->count() . ' firewall(s).']);
    }

    private function queueUndo(Deployment $deployment, $targets, string $by): void
    {
        Log::info('Deployment: undo', ['deployment' => $deployment->id, 'by' => $by, 'firewalls' => $targets->pluck('firewall_id')->all()]);
        foreach ($targets as $t) {
            $t->update(['status' => 'undoing', 'message' => 'Undoing…']);
        }
        if ($deployment->status === 'completed') {
            $deployment->update(['status' => 'running']);
        }
        foreach ($targets as $t) {
            UndoDeploymentTargetJob::dispatch($t->id);
        }
        $deployment->refresh()->refreshStatus();
    }

    public function stop(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        $deployment->update(['status' => 'stopped', 'scheduled_at' => null, 'scheduled_wave' => null]);
        $deployment->targets()->whereIn('status', ['pending', 'checking', 'ready', 'problem', 'queued'])->update(['status' => 'skipped', 'message' => 'Stopped by ' . $request->user()->email . '.']);
        Log::info('Deployment: stopped', ['deployment' => $deployment->id, 'by' => $request->user()->email]);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Deployment $deployment)
    {
        $this->mayDeploy($request);
        $this->visible($request, $deployment);
        abort_unless($deployment->isDraft(), 409, 'A deployment that has started is kept as a record; stop it instead.');
        $deployment->delete();
        return redirect()->route('deployments.index')->with('success', 'Deployment deleted. Nothing had been changed on the firewalls.');
    }

    /** A firewall's rules, aliases, NAT, DNS and other settings, as changes that can be copied to other firewalls. */
    public function export(Request $request, Firewall $firewall)
    {
        $this->mayDeploy($request);
        abort_unless(DeployRunner::firewallsFor($request->user())->whereKey($firewall->id)->exists(), 404);
        abort_if($firewall->isOpnSense(), 422, 'Copying is for pfSense firewalls.');
        try {
            $answer = (new DeployHelper($firewall))->export();
        } catch (\Throwable $e) {
            abort(502, 'Couldn\'t read the firewall: ' . $e->getMessage());
        }
        $out = [];
        foreach ((array) ($answer['items'] ?? []) as $raw) {
            [$items, $errors] = DeployItems::normalize([$raw]);
            if ($items && !$errors) {
                $out[] = $items[0] + ['kindLabel' => DeployItems::KINDS[$items[0]['kind']]];
            }
        }
        return response()->json(['firewall' => $firewall->name, 'version' => $answer['version'] ?? null, 'items' => $out]);
    }

    /** Microsoft 365 people or groups of the MSP's tenant, to pick who can deploy. */
    public function accessSearch(Request $request)
    {
        $this->manageAccess($request);
        $request->validate(['type' => ['required', 'in:users,groups'], 'q' => ['nullable', 'string', 'max:100']]);
        abort_unless(DeployAccess::canPick(), 409, 'Set up "Sign in with Microsoft 365" first (Sign-in settings): people and groups come from that tenant.');
        try {
            $ms = MspSignIn::microsoft();
            $q = (string) $request->input('q', '');
            $results = $request->input('type') === 'users'
                ? collect($ms->searchUsers((string) MspSignIn::tenantId(), $q))->filter(fn ($u) => $u['enabled'] && !$u['guest'])->map(fn ($u) => ['oid' => $u['oid'], 'name' => $u['name'], 'upn' => $u['upn']])->values()
                : collect($ms->searchGroups((string) MspSignIn::tenantId(), $q))->map(fn ($g) => ['id' => $g['id'], 'name' => $g['name']])->values();
        } catch (\Throwable $e) {
            abort(502, $e->getMessage());
        }
        return response()->json(['results' => $results]);
    }

    public function accessSave(Request $request)
    {
        $this->manageAccess($request);
        $request->validate(['users' => ['array', 'max:' . DeployAccess::MAX], 'groups' => ['array', 'max:' . DeployAccess::MAX]]);
        DeployAccess::set((array) $request->input('users', []), (array) $request->input('groups', []), $request->user()->email);
        return response()->json(['ok' => true, 'access' => DeployAccess::get()]);
    }

    private function manageAccess(Request $request): void
    {
        abort_unless($request->user()->isGlobalAdmin() && !$request->user()->isReadOnly(), 403, 'Only global administrators choose who can deploy.');
    }

    private function mayDeploy(Request $request): void
    {
        if ($why = DeployAccess::why($request->user())) {
            abort(403, $why);
        }
    }

    private function pickable(Request $request): array
    {
        return DeployRunner::firewallsFor($request->user())->get()
            ->map(fn (Firewall $f) => ['id' => $f->id, 'name' => $f->name, 'company' => $f->company?->name ?? '—', 'opn' => $f->isOpnSense()])->values()->all();
    }

    private function visible(Request $request, Deployment $deployment): void
    {
        abort_unless($deployment->visibleTo($request->user()), 404);
    }

    private function target(Request $request, Deployment $deployment, DeploymentTarget $target): void
    {
        $this->visible($request, $deployment);
        abort_unless((int) $target->deployment_id === (int) $deployment->id, 404);
    }
}
