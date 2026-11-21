@php
    $settings = $settings ?? \App\Models\SystemSetting::pluck('value', 'key')->toArray();
    $favicon = $settings['icon_path'] ?? ($settings['favicon_path'] ?? asset('favicon.svg'));
    $version = \App\Support\ReleaseNotes::currentVersion();
    $isLogin = request()->routeIs('login');
    $css = public_path('css/pfcentral-auth.css');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0E2238">
    <title>{{ $isLogin ? 'Sign in' : 'Account' }} · {{ config('brand.name') }} by {{ config('brand.owner') }}</title>
    <link rel="icon" href="{{ $favicon }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    <link rel="manifest" href="{{ route('manifest') }}">
    <script type="importmap">{ "imports": { "three": "{{ asset('vendor/three/three.module.min.js') }}" } }</script>
    @vite(['resources/js/app.js'])
    {{-- Sign-in styles are plain CSS so these pages never depend on a rebuilt asset bundle. --}}
    <link rel="stylesheet" href="{{ asset('css/pfcentral-auth.css') }}?v={{ is_file($css) ? filemtime($css) : 1 }}">
    <link rel="modulepreload" href="{{ asset('vendor/three/three.module.min.js') }}">
</head>
<body class="pfc-auth">
    <canvas id="pfc-scene" aria-hidden="true"></canvas>
    <div class="pfc-fallback" aria-hidden="true"><img src="{{ asset('images/cfbtel-mark.svg') }}" alt=""></div>

    <div class="pfc-shell">
        <header class="pfc-top">
            <a href="{{ url('/') }}" class="pfc-brand" aria-label="{{ config('brand.name') }} by {{ config('brand.owner') }}">
                <img src="{{ asset('images/cfbtel-logo.svg') }}" alt="{{ config('brand.owner') }}">
                <span class="pfc-brand-sep" aria-hidden="true"></span>
                <span class="pfc-brand-product">{{ config('brand.name') }}</span>
            </a>
            @if ($isLogin)
                <a href="{{ route('system.signin') }}" class="pfc-gear" title="Sign-in settings (administrators)">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>Sign-in settings</span>
                </a>
            @endif
        </header>

        <main class="pfc-main">
            <div class="pfc-panel">
                {{ $slot }}
            </div>
        </main>

        <footer class="pfc-foot">
            <p>&copy; {{ max(now()->year, config('brand.since')) }} {{ config('brand.owner') }}. All rights reserved.</p>
            <p class="num">{{ config('brand.name') }} {{ $version }}</p>
        </footer>
    </div>

    <script type="module" src="{{ asset('js/login-scene.js') }}?v={{ is_file(public_path('js/login-scene.js')) ? filemtime(public_path('js/login-scene.js')) : 1 }}"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
        }
    </script>
</body>
</html>
