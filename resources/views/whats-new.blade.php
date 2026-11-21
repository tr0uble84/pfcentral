<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">What's new in {{ config('brand.name') }}</h2>
    </x-slot>
    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @foreach ($versions as $version => $sections)
                <article class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        {{ $version }}
                        @if ($version === $current)<span class="px-2 py-0.5 rounded-full text-xs font-medium bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-200">Installed</span>@endif
                    </h3>
                    @foreach ($sections as $section)
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $section['type'] }}</h4>
                        <div class="release-notes mt-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{!! $section['html'] !!}</div>
                    @endforeach
                </article>
            @endforeach
        </div>
    </div>
</x-app-layout>
