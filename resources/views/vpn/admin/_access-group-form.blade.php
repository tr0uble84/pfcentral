{{-- Create (no $ag) or edit an access group. Uses $company, $sites, $users, $input, $btn2 from the page. --}}
@php
    $isOld = old('group_id') == ($ag?->id ?? 'new');
    $pickedSites = $isOld ? array_map('intval', (array) old('sites', [])) : ($ag?->sites->pluck('id')->all() ?? []);
    $pickedUsers = $isOld ? array_map('intval', (array) old('users', [])) : ($ag?->users->pluck('id')->all() ?? []);
    $everyone = $isOld ? (bool) old('everyone') : (bool) ($ag?->everyone);
    $savedMs = $ag ? $ag->links->where('provider', 'microsoft')->map(fn ($l) => ['id' => $l->external_id, 'name' => $l->external_name ?: $l->external_id])->values()->all() : [];
    if ($isOld && old('ms_groups_present')) {
        $names = collect($savedMs)->pluck('name', 'id');
        $savedMs = collect((array) old('ms_groups', []))->filter(fn ($id) => is_string($id))->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? $id])->values()->all();
    }
@endphp
<form method="POST" action="{{ $ag ? route('vpn.access-groups.update', [$company, $ag]) : route('vpn.access-groups.store', $company) }}" class="mt-3 space-y-3 max-w-xl">@csrf
    <input type="hidden" name="group_id" value="{{ $ag?->id ?? 'new' }}">
    <div>
        <label class="block text-xs font-semibold mb-1">Group name</label>
        <input name="name" value="{{ $isOld ? old('name') : $ag?->name }}" maxlength="80" required placeholder="e.g. Office staff" class="w-full {{ $input }}">
    </div>
    <div>
        <p class="text-xs font-semibold mb-1">Firewalls in this group</p>
        @foreach($sites->values() as $gs)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="sites[]" value="{{ $gs->id }}" @checked(in_array($gs->id, $pickedSites, true))> {{ $gs->firewall?->name }} @unless($gs->isReady())<span class="text-xs text-gray-500">({{ $gs->status }})</span>@endunless</label>
        @endforeach
    </div>
    <div>
        <p class="text-xs font-semibold mb-1">Members</p>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="everyone" value="1" @checked($everyone)> Everyone with VPN access in this company</label>
        @if($users->isNotEmpty())
            <p class="text-xs text-gray-500 mt-2">…or these people:</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
                @foreach($users as $gu)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="users[]" value="{{ $gu->id }}" @checked(in_array($gu->id, $pickedUsers, true))> {{ $gu->name }} <span class="text-xs text-gray-500">{{ $gu->upn }}</span></label>
                @endforeach
            </div>
        @endif
    </div>
    @if($vc->ms_tenant_id)
        <div>
            <p class="text-xs font-semibold mb-1">…and members of these Microsoft 365 groups <span class="font-normal text-gray-500">(optional)</span></p>
            @include('vpn.admin._ms-group-picker', ['pickerName' => 'ms_groups', 'pickerSelected' => $savedMs])
        </div>
    @else
        <p class="text-xs text-gray-500">Connect Microsoft 365 to add its groups too.</p>
    @endif
    <button class="{{ $btn2 }}">{{ $ag ? 'Save group' : 'Create group' }}</button>
</form>
