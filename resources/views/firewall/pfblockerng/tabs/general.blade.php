@php $o = $status['options'] ?? []; $g = $general; @endphp

<form method="POST" action="{{ route('firewall.pfblockerng.general', $firewall) }}" class="{{ $ui['card'] }} p-6 space-y-6 max-w-3xl">
    @csrf
    <fieldset @disabled($ro) class="space-y-6">
        <div>
            <label class="flex items-start gap-3">
                <input type="checkbox" name="enable_cb" value="on" class="{{ $ui['check'] }} mt-0.5" @checked(old('enable_cb', $g['enable_cb'] ?? '') === 'on')>
                <span>
                    <span class="{{ $ui['label'] }}">Enable pfBlockerNG</span>
                    <span class="{{ $ui['help'] }} block">Turning this off removes the pfBlockerNG firewall rules and DNSBL from the firewall.</span>
                </span>
            </label>
        </div>
        <div>
            <label class="flex items-start gap-3">
                <input type="checkbox" name="pfb_keep" value="on" class="{{ $ui['check'] }} mt-0.5" @checked(old('pfb_keep', $g['pfb_keep'] ?? 'on') === 'on')>
                <span>
                    <span class="{{ $ui['label'] }}">Keep settings</span>
                    <span class="{{ $ui['help'] }} block">Keep the settings and downloaded lists if the package is reinstalled or upgraded.</span>
                </span>
            </label>
        </div>

        <div class="grid sm:grid-cols-2 gap-6">
            <div>
                <label for="pfb_interval" class="{{ $ui['label'] }}">Update frequency</label>
                <select id="pfb_interval" name="pfb_interval" class="{{ $ui['input'] }} mt-1">
                    @foreach ($o['interval'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) old('pfb_interval', $g['pfb_interval']) === (string) $v)>{{ $l }}</option>@endforeach
                </select>
                <p class="{{ $ui['help'] }}">How often pfBlockerNG checks its feeds. Each group has its own frequency too.</p>
            </div>
            <div>
                <label for="pfb_min" class="{{ $ui['label'] }}">At minute</label>
                <select id="pfb_min" name="pfb_min" class="{{ $ui['input'] }} mt-1">
                    @foreach ($o['min'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) old('pfb_min', $g['pfb_min']) === (string) $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="pfb_hour" class="{{ $ui['label'] }}">Start hour</label>
                <select id="pfb_hour" name="pfb_hour" class="{{ $ui['input'] }} mt-1">
                    @foreach ($o['hour'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) old('pfb_hour', $g['pfb_hour']) === (string) $v)>{{ sprintf('%02d:00', $l) }}</option>@endforeach
                </select>
                <p class="{{ $ui['help'] }}">First hour of the day for updates that run every 2 hours or less often.</p>
            </div>
            <div>
                <label for="pfb_dailystart" class="{{ $ui['label'] }}">Daily and weekly start hour</label>
                <select id="pfb_dailystart" name="pfb_dailystart" class="{{ $ui['input'] }} mt-1">
                    @foreach ($o['hour'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) old('pfb_dailystart', $g['pfb_dailystart']) === (string) $v)>{{ sprintf('%02d:00', $l) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="skipfeed" class="{{ $ui['label'] }}">Download failure limit</label>
                <select id="skipfeed" name="skipfeed" class="{{ $ui['input'] }} mt-1">
                    @foreach ($o['skipfeed'] ?? [] as $v => $l)<option value="{{ $v }}" @selected((string) old('skipfeed', $g['skipfeed']) === (string) $v)>{{ $l }}</option>@endforeach
                </select>
                <p class="{{ $ui['help'] }}">Stop trying a feed after this many failed downloads in a row.</p>
            </div>
        </div>
    </fieldset>

    @unless ($ro)
        <div class="pt-2 border-t border-gray-100 dark:border-gray-700">
            <button type="submit" class="{{ $ui['btn'] }}">Save general settings</button>
        </div>
    @endunless
</form>
