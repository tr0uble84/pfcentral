{{-- "What's new" window: opens once after each update, and again from the version link in the sidebar. --}}
@php
    $wnVersion = \App\Support\ReleaseNotes::currentVersion();
    $wnAuto = \App\Support\ReleaseNotes::unseenBy(auth()->user());
    // After an update: everything since the version this person last closed. Opened by hand: the latest three.
    $wnVersions = $wnAuto
        ? \App\Support\ReleaseNotes::since(auth()->user()->whats_new_seen)
        : collect(\App\Support\ReleaseNotes::all())->keys()
            ->filter(fn ($v) => version_compare(ltrim($v, 'v'), ltrim($wnVersion, 'v'), '<='))
            ->take(3)->mapWithKeys(fn ($v) => [$v => \App\Support\ReleaseNotes::for($v)])->all();
    // Shown once: it counts as seen as soon as it opens, even if the person just moves on to another page.
    // A page the browser loads ahead (on hover) may never be seen, so it's marked seen when it's shown instead.
    $wnAhead = str_contains((string) request()->header('Sec-Purpose'), 'prerender');
    if ($wnAuto && !$wnAhead) {
        auth()->user()->forceFill(['whats_new_seen' => $wnVersion])->saveQuietly();
    }
@endphp
<div x-data="{
        open: @js($wnAuto),
        init() {
            if (!@js($wnAuto && $wnAhead)) return;
            const seen = () => fetch(@js(route('whats-new.seen')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
            document.prerendering ? document.addEventListener('prerenderingchange', seen, { once: true }) : seen();
        },
        close() {
            this.open = false;
            fetch(@js(route('whats-new.seen')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
        }
    }"
    @open-whats-new.window="open = true"
    @keydown.escape.window="open && close()"
    x-cloak>
    <div x-show="open" class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="whats-new-title">
        <div x-show="open" x-transition.opacity.duration.200ms class="absolute inset-0" style="background:rgba(14,34,56,.6)" @click="close()"></div>
        <div x-show="open"
            x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-3 scale-[0.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            x-transition:leave="ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="relative w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden rounded-2xl bg-white dark:bg-gray-900 shadow-[0_24px_60px_-12px_rgba(14,34,56,0.45)] ring-1 ring-black/5 dark:ring-white/10">
            <div class="relative px-6 pt-6 pb-5 text-white overflow-hidden" style="background:#0E2238;color:#fff">
                <div class="relative flex items-start gap-4">
                    <img src="{{ asset('images/cfbtel-mark.svg') }}" alt="" class="h-10 w-auto shrink-0" aria-hidden="true">
                    <div class="flex-1 min-w-0">
                        <h2 id="whats-new-title" class="text-xl font-semibold tracking-tight">{{ config('brand.name') }} has been updated</h2>
                        <p class="mt-1 text-sm" style="color:#A8E3F9">You're now on <span class="font-semibold text-white">{{ $wnVersion }}</span>. Here's what changed.</p>
                    </div>
                    <button type="button" @click="close()" class="p-1.5 -m-1.5 rounded-md text-brand-200 hover:text-white hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400" aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">
                @forelse ($wnVersions as $version => $sections)
                    <div>
                        @if (count($wnVersions) > 1 || $version !== $wnVersion)
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ $version }}</h3>
                        @endif
                        <div class="space-y-5">
                            @foreach ($sections as $section)
                                <section>
                                    <h4 @class(['inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wide',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => $section['type'] === 'Added',
                                        'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-200' => $section['type'] === 'Changed',
                                        'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' => $section['type'] === 'Fixed',
                                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => !in_array($section['type'], ['Added', 'Changed', 'Fixed'])])>{{ $section['type'] }}</h4>
                                    <div class="release-notes mt-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{!! $section['html'] !!}</div>
                                </section>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-400">There are no release notes for {{ $wnVersion }} on this server.</p>
                @endforelse
            </div>
            <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
                <a href="{{ route('whats-new') }}" class="text-sm font-medium text-brand-700 hover:text-brand-900 dark:text-brand-300 dark:hover:text-brand-200">All release notes</a>
                <button type="button" @click="close()" style="background:#0A84BA;color:#fff" class="inline-flex items-center px-4 py-2 rounded-md hover:opacity-90 text-white text-sm font-medium shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">Got it</button>
            </div>
        </div>
    </div>
</div>
