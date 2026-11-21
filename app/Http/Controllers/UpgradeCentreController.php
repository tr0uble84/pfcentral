<?php

namespace App\Http\Controllers;

use App\Jobs\CheckFirmwareJob;
use App\Jobs\RunUpgradeJob;
use App\Jobs\WatchUpgradeJob;
use App\Models\Firewall;
use App\Models\FirmwareCheck;
use App\Models\FirmwareRollout;
use App\Models\FirmwareRolloutItem;
use App\Services\Upgrades\Firmware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Upgrade centre: pre-checks for every firewall, and upgrades in waves. Each wave is started by hand; a wave can
 * start only when every firewall in the earlier waves finished well.
 */
class UpgradeCentreController extends Controller
{
    public function index(Request $request)
    {
        return view('upgrades.index', ['data' => $this->overview($request)]);
    }

    public function data(Request $request)
    {
        return response()->json($this->overview($request));
    }

    private function overview(Request $request): array
    {
        $user = $request->user();
        $firewalls = Firmware::firewallsFor($user)->get();
        $checks = FirmwareCheck::whereIn('firewall_id', $firewalls->pluck('id'))->get()->keyBy('firewall_id');
        $active = FirmwareRolloutItem::whereIn('firewall_id', $firewalls->pluck('id'))
            ->whereNotIn('status', array_merge(FirmwareRolloutItem::DONE, ['stopped']))
            ->whereHas('rollout', fn ($q) => $q->whereNotIn('status', ['stopped', 'completed']))
            ->get()->keyBy('firewall_id');

        $rollouts = FirmwareRollout::withCount([
            'items',
            'items as done_count' => fn ($q) => $q->whereIn('status', FirmwareRolloutItem::DONE),
            'items as attention_count' => fn ($q) => $q->whereIn('status', FirmwareRolloutItem::NEEDS_ATTENTION),
        ])->orderByDesc('id')->limit(30)->get()->filter(fn ($r) => $r->visibleTo($user))->take(10);

        return [
            'firewalls' => $firewalls->map(function (Firewall $f) use ($checks, $active) {
                $c = $checks->get($f->id);
                $a = $active->get($f->id);
                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'company' => $f->company?->name ?? '—',
                    'os' => $f->isOpnSense() ? 'opnsense' : 'pfsense',
                    'check' => $c ? [
                        'status' => $c->status,
                        'version' => $c->version,
                        'available' => $c->available_version,
                        'up_to_date' => $c->up_to_date,
                        'checks' => $c->checks ?? [],
                        'error' => $c->error,
                        'at' => $c->checked_at?->toIso8601String(),
                    ] : null,
                    'rollout' => $a ? ['id' => $a->firmware_rollout_id, 'status' => $a->status, 'url' => route('upgrades.rollouts.show', $a->firmware_rollout_id)] : null,
                ];
            })->values(),
            'rollouts' => $rollouts->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'status' => $r->status, 'items' => $r->items_count, 'done' => $r->done_count,
                'attention' => $r->attention_count, 'created_by' => $r->created_by, 'created' => $r->created_at?->toIso8601String(),
                'url' => route('upgrades.rollouts.show', $r),
            ])->values(),
            'can_manage' => !$user->isReadOnly(),
        ];
    }

    /** Selected firewalls from a request, limited to those this person may upgrade. */
    private function selected(Request $request): \Illuminate\Support\Collection
    {
        $ids = array_map('intval', (array) $request->input('firewall_ids', []));
        return Firmware::firewallsFor($request->user())->whereIn('id', $ids)->get();
    }

    public function check(Request $request)
    {
        $request->validate(['firewall_ids' => ['required', 'array', 'max:500'], 'firewall_ids.*' => ['integer']]);
        $n = 0;
        foreach ($this->selected($request) as $fw) {
            if ($fw->isOpnSense()) {
                continue;
            }
            FirmwareCheck::updateOrCreate(['firewall_id' => $fw->id], ['status' => 'running', 'error' => null]);
            CheckFirmwareJob::dispatch($fw->id);
            $n++;
        }
        return response()->json(['ok' => true, 'message' => $n ? "Checking {$n} firewall(s). Results appear in a minute or two." : 'No pfSense firewalls were selected.']);
    }

    /** Make a draft rollout: the first firewall is wave 1 (the test), the rest wave 2. */
    public function plan(Request $request)
    {
        $request->validate(['firewall_ids' => ['required', 'array', 'max:500'], 'firewall_ids.*' => ['integer'], 'name' => ['nullable', 'string', 'max:120']]);
        $user = $request->user();
        $order = array_map('intval', (array) $request->input('firewall_ids'));
        $firewalls = $this->selected($request)->sortBy(fn ($f) => array_search($f->id, $order, true))->values();
        $busy = FirmwareRolloutItem::whereIn('firewall_id', $firewalls->pluck('id'))->whereIn('status', array_merge(['pending'], FirmwareRolloutItem::ACTIVE, FirmwareRolloutItem::NEEDS_ATTENTION))
            ->whereHas('rollout', fn ($q) => $q->whereNotIn('status', ['stopped', 'completed']))->pluck('firewall_id')->all();
        $skipped = [];
        $use = $firewalls->filter(function ($f) use ($busy, &$skipped) {
            if ($f->isOpnSense()) {
                $skipped[] = "{$f->name} (OPNsense isn't supported here yet)";
                return false;
            }
            if (in_array($f->id, $busy, true)) {
                $skipped[] = "{$f->name} (already in another upgrade)";
                return false;
            }
            return true;
        })->values();
        if ($use->isEmpty()) {
            return back()->with('error', 'Nothing to upgrade. ' . ($skipped ? 'Left out: ' . implode(', ', $skipped) . '.' : ''));
        }
        $companies = $use->pluck('company_id')->unique();
        $rollout = FirmwareRollout::create([
            'name' => $request->input('name') ?: 'Upgrade ' . now()->format('M j, Y') . ' (' . $use->count() . ' firewall' . ($use->count() === 1 ? '' : 's') . ')',
            'company_id' => $user->isGlobalAdmin() ? ($companies->count() === 1 ? $companies->first() : null) : $user->company_id,
            'created_by' => $user->email,
            'status' => 'draft',
        ]);
        foreach ($use as $i => $fw) {
            $rollout->items()->create(['firewall_id' => $fw->id, 'wave' => $i === 0 ? 1 : 2, 'status' => 'pending']);
        }
        Log::info('Upgrade centre: rollout planned', ['rollout' => $rollout->id, 'by' => $user->email, 'firewalls' => $use->pluck('id')->all()]);
        return redirect()->route('upgrades.rollouts.show', $rollout)->with($skipped ? 'warning' : 'success', $skipped ? 'Left out: ' . implode(', ', $skipped) . '.' : 'Upgrade planned. Review the waves, then start wave 1.');
    }

    private function rollout(Request $request, FirmwareRollout $rollout): FirmwareRollout
    {
        abort_unless($rollout->visibleTo($request->user()), 404);
        return $rollout;
    }

    private function item(FirmwareRollout $rollout, FirmwareRolloutItem $item): FirmwareRolloutItem
    {
        abort_unless((int) $item->firmware_rollout_id === (int) $rollout->id, 404);
        return $item;
    }

    public function show(Request $request, FirmwareRollout $rollout)
    {
        $this->rollout($request, $rollout);
        return view('upgrades.rollout', ['data' => $this->rolloutData($request, $rollout)]);
    }

    public function rolloutJson(Request $request, FirmwareRollout $rollout)
    {
        $this->rollout($request, $rollout);
        return response()->json($this->rolloutData($request, $rollout));
    }

    private function rolloutData(Request $request, FirmwareRollout $rollout): array
    {
        $rollout->load(['items.firewall.company']);
        $checks = FirmwareCheck::whereIn('firewall_id', $rollout->items->pluck('firewall_id'))->get()->keyBy('firewall_id');
        $waves = [];
        foreach ($rollout->items->sortBy(fn ($i) => [$i->wave, strtolower($i->firewall?->name ?? '')])->groupBy('wave') as $wave => $items) {
            [$canStart, $why] = $this->canStartWave($rollout, (int) $wave);
            $waves[] = [
                'wave' => (int) $wave,
                'can_start' => $canStart,
                'blocked' => $why,
                'started' => $items->contains(fn ($i) => $i->status !== 'pending'),
                'items' => $items->map(fn ($i) => [
                    'id' => $i->id,
                    'firewall' => ['id' => $i->firewall_id, 'name' => $i->firewall?->name ?? '—', 'company' => $i->firewall?->company?->name ?? '—'],
                    'status' => $i->status,
                    'from' => $i->from_version ?? $checks->get($i->firewall_id)?->version,
                    'to' => $i->to_version ?? $checks->get($i->firewall_id)?->available_version,
                    'check' => ($c = $checks->get($i->firewall_id)) ? ['status' => $c->status, 'checks' => $c->checks ?? [], 'error' => $c->error, 'up_to_date' => $c->up_to_date, 'at' => $c->checked_at?->toIso8601String()] : null,
                    'message' => $i->message,
                    'log' => $i->log_tail,
                    'backup' => $i->backup_path ? route('upgrades.rollouts.backup', [$rollout, $i]) : null,
                    'backup_at' => $i->backup_at?->toIso8601String(),
                    'started' => $i->started_at?->toIso8601String(),
                    'finished' => $i->finished_at?->toIso8601String(),
                ])->values(),
            ];
        }
        return [
            'rollout' => ['id' => $rollout->id, 'name' => $rollout->name, 'status' => $rollout->status, 'created_by' => $rollout->created_by, 'created' => $rollout->created_at?->toIso8601String()],
            'waves' => $waves,
            'can_manage' => !$request->user()->isReadOnly(),
            'now' => now()->toIso8601String(),
        ];
    }

    /** @return array{0: bool, 1: ?string} */
    private function canStartWave(FirmwareRollout $rollout, int $wave): array
    {
        if (in_array($rollout->status, ['stopped', 'completed'], true)) {
            return [false, 'This upgrade is ' . $rollout->status . '.'];
        }
        $items = $rollout->items;
        if (!$items->where('wave', $wave)->where('status', 'pending')->count()) {
            return [false, null];
        }
        $earlier = $items->filter(fn ($i) => $i->wave < $wave);
        if ($earlier->contains(fn ($i) => !in_array($i->status, FirmwareRolloutItem::DONE, true))) {
            return [false, 'Finish the earlier waves first. A firewall that needs attention can be retried or skipped.'];
        }
        return [true, null];
    }

    public function setWave(Request $request, FirmwareRollout $rollout, FirmwareRolloutItem $item)
    {
        $this->rollout($request, $rollout);
        $this->item($rollout, $item);
        $v = $request->validate(['wave' => ['required', 'integer', 'between:1,9']]);
        abort_unless($item->status === 'pending', 409, 'This firewall has already started.');
        $item->update(['wave' => $v['wave']]);
        return response()->json(['ok' => true]);
    }

    public function removeItem(Request $request, FirmwareRollout $rollout, FirmwareRolloutItem $item)
    {
        $this->rollout($request, $rollout);
        $this->item($rollout, $item);
        abort_unless($item->status === 'pending', 409, 'This firewall has already started.');
        $item->delete();
        if (!$rollout->items()->exists()) {
            $rollout->delete();
            return response()->json(['ok' => true, 'redirect' => route('upgrades.index')]);
        }
        $rollout->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function startWave(Request $request, FirmwareRollout $rollout, int $wave)
    {
        $this->rollout($request, $rollout);
        $rollout->load('items');
        [$ok, $why] = $this->canStartWave($rollout, $wave);
        abort_unless($ok, 409, $why ?? 'Nothing to start in this wave.');
        $items = $rollout->items->where('wave', $wave)->where('status', 'pending');
        $rollout->update(['status' => 'running', 'current_wave' => $wave]);
        foreach ($items as $item) {
            $item->update(['status' => 'queued', 'message' => 'Waiting to start…']);
        }
        Log::info('Upgrade centre: wave started', ['rollout' => $rollout->id, 'wave' => $wave, 'by' => $request->user()->email]);
        foreach ($items as $item) {
            RunUpgradeJob::dispatch($item->id);
        }
        $rollout->refreshStatus();
        return response()->json(['ok' => true, 'message' => 'Wave ' . $wave . ' started: ' . $items->count() . ' firewall(s).']);
    }

    public function retry(Request $request, FirmwareRollout $rollout, FirmwareRolloutItem $item)
    {
        $this->rollout($request, $rollout);
        $this->item($rollout, $item);
        abort_if($rollout->status === 'stopped', 409, 'This upgrade was stopped.');
        if ($item->status === 'needs_api') {
            // The REST API was reinstalled by hand: look again.
            $item->update(['status' => 'upgrading', 'api_misses' => 0, 'message' => 'Checking the firewall again…']);
            WatchUpgradeJob::dispatch($item->id);
        } elseif ($item->status === 'failed') {
            $item->update(['status' => 'queued', 'message' => 'Trying again…', 'finished_at' => null, 'started_at' => null, 'api_misses' => 0, 'api_reinstall_tried' => false]);
            $rollout->update(['status' => 'running']);
            RunUpgradeJob::dispatch($item->id);
        } else {
            abort(409, 'Only a firewall that needs attention can be retried.');
        }
        $rollout->refresh()->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function skip(Request $request, FirmwareRollout $rollout, FirmwareRolloutItem $item)
    {
        $this->rollout($request, $rollout);
        $this->item($rollout, $item);
        abort_unless(in_array($item->status, array_merge(['pending'], FirmwareRolloutItem::NEEDS_ATTENTION), true), 409, 'This firewall is being upgraded right now.');
        $item->update(['status' => 'skipped', 'message' => trim(($item->message ? $item->message . ' ' : '') . 'Skipped by ' . $request->user()->email . '.'), 'finished_at' => now()]);
        $rollout->refreshStatus();
        return response()->json(['ok' => true]);
    }

    public function stop(Request $request, FirmwareRollout $rollout)
    {
        $this->rollout($request, $rollout);
        $rollout->items()->where('status', 'pending')->update(['status' => 'stopped', 'message' => 'Not started: the upgrade was stopped.']);
        $rollout->update(['status' => 'stopped']);
        Log::info('Upgrade centre: rollout stopped', ['rollout' => $rollout->id, 'by' => $request->user()->email]);
        return response()->json(['ok' => true, 'message' => 'Stopped. Firewalls already upgrading finish on their own; nothing else will start.']);
    }

    public function backup(Request $request, FirmwareRollout $rollout, FirmwareRolloutItem $item)
    {
        $this->rollout($request, $rollout);
        $this->item($rollout, $item);
        abort_unless($item->backup_path && !$request->user()->isReadOnly(), 404);
        $xml = Firmware::readBackup($item->backup_path);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $item->firewall?->name ?? 'firewall') . '-config-' . $item->backup_at?->format('Ymd-His') . '.xml';
        Log::info('Upgrade centre: backup downloaded', ['item' => $item->id, 'by' => $request->user()->email]);
        return response($xml, 200, ['Content-Type' => 'application/xml', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Cache-Control' => 'no-store']);
    }
}
