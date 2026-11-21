@php
    $card = 'bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 text-gray-900 dark:text-gray-100';
    $btn2 = 'inline-flex items-center px-3 py-1.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-600';
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $r->reference() }} · {{ $r->subject }}</h2>
        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            <a href="{{ route('vpn.company', $company) }}" class="font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">← {{ $company->name }} VPN</a>
        </div>
    </x-slot>
    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="{{ $card }}">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-gray-500">Sent</dt><dd class="sm:col-span-2">{{ $r->created_at }} @if($r->emailed_at)· emailed to {{ \App\Http\Controllers\Vpn\AppSupportController::supportAddress() }}@else<span class="text-yellow-600 dark:text-yellow-400"> · not emailed{{ $r->email_error ? ': ' . $r->email_error : '' }}</span>@endif</dd>
                    <dt class="text-gray-500">From</dt><dd class="sm:col-span-2">{{ $r->contact_name }} &lt;<a class="text-indigo-600 dark:text-indigo-400" href="mailto:{{ $r->contact_email }}?subject={{ rawurlencode('Re: [' . $r->reference() . '] ' . $r->subject) }}">{{ $r->contact_email }}</a>&gt;</dd>
                    <dt class="text-gray-500">VPN person</dt><dd class="sm:col-span-2">{{ $r->person ?? '(access removed)' }}</dd>
                    <dt class="text-gray-500">Device</dt><dd class="sm:col-span-2">{{ $r->device_name }} · computer {{ $r->computer }} · ID {{ $r->device_id }} · {{ $r->app }}</dd>
                    <dt class="text-gray-500">Checks</dt><dd class="sm:col-span-2">{{ $r->problems }} problem(s), {{ $r->warnings }} warning(s)</dd>
                </dl>
                <p class="text-sm font-semibold mt-4">Message</p>
                <p class="text-sm whitespace-pre-line mt-1">{{ $r->message }}</p>
            </div>
            <div class="{{ $card }}">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-bold">Diagnostic report and log</h3>
                    <a href="{{ route('vpn.support.show', [$company, $r->id]) }}?download=1" class="{{ $btn2 }}">Download .txt</a>
                </div>
                <pre class="text-xs overflow-x-auto whitespace-pre max-h-[70vh] p-3 rounded bg-gray-50 dark:bg-gray-900">{{ $r->report }}</pre>
            </div>
        </div>
    </div>
</x-app-layout>
