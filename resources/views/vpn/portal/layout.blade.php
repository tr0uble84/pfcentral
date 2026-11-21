<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? 'CFBTEL VPN' }}</title>
    <link rel="stylesheet" href="/vpn-assets/portal.css">
    <link rel="icon" href="/vpn-assets/cfbtel-mark.svg">
</head>
<body>
<main class="card">
    <header>
        <img class="logo" src="/vpn-assets/cfbtel-logo.svg" alt="CFBTEL">
        @isset($user)
            <nav class="top">
                <a href="/vpn">My devices</a>
                <a href="/vpn/help">Help</a>
                <span class="small">{{ $user->name }}</span>
                <form class="inline" method="POST" action="/vpn/logout">@csrf<button class="link" type="submit">Sign out</button></form>
            </nav>
        @endisset
    </header>
    @yield('body')
</main>
</body>
</html>
