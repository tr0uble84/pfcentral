{{-- VPN Access: Live connections | Companies & settings --}}
<nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700" aria-label="VPN Access">
    @foreach([['vpn.live', 'Live connections'], ['vpn.settings', 'Companies & settings']] as [$r, $label])
        <a href="{{ route($r) }}"
           @class([
               'px-4 py-2.5 -mb-px text-sm font-medium border-b-2 transition-colors',
               'border-brand-500 text-gray-900 dark:text-white' => request()->routeIs($r),
               'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200' => !request()->routeIs($r),
           ])
           @if(request()->routeIs($r)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
