{{--
    Faster moving between pages, with no change to how pages work:
    - The browser starts loading a page while the pointer rests on its link (Chrome and Edge),
      so most clicks open at once. Links that download, sign in or out, or restart things are never loaded ahead.
    - Pages swap without a white flash; the sidebar stays put.
    - A thin bar at the top shows a page is on its way when it takes more than a moment.
--}}
<script type="speculationrules">
{
    "prerender": [{
        "where": { "and": [
            { "href_matches": "/*" },
            { "not": { "href_matches": [
                "/login*", "/logout*", "/register*", "/forgot-password*", "/reset-password*", "/two-factor*", "/user/*",
                "/vpn/*", "/__pfcentral/*", "/*download*", "/*/download/*", "/*backup*", "/*reboot*", "/*export*",
                "/*/web-interface*", "/sso/*", "/auth/*", "/*/callback*"
            ] } },
            { "not": { "selector_matches": "[download], [target], [data-no-prerender], [data-no-prerender] a, [onclick]" } }
        ] },
        "eagerness": "moderate"
    }]
}
</script>
<style>
    @view-transition { navigation: auto; }
    #pfc-sidebar { view-transition-name: pfc-sidebar; }
    ::view-transition-old(root), ::view-transition-new(root) { animation-duration: 120ms; }
    @media (prefers-reduced-motion: reduce) {
        @view-transition { navigation: none; }
    }
    #pfc-nav-bar {
        position: fixed; top: 0; left: 0; z-index: 100; height: 2px; width: 0; opacity: 0; pointer-events: none;
        background: #33C5F3; box-shadow: 0 1px 4px rgba(51, 197, 243, .45);
        transition: width 8s cubic-bezier(.08, .7, .2, 1), opacity .2s ease-out;
    }
    #pfc-nav-bar.is-going { width: 85%; opacity: 1; }
</style>
<script>
    (function () {
        var timer = null;
        function bar() { return document.getElementById('pfc-nav-bar'); }
        function start() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var b = bar();
                if (b) { b.classList.add('is-going'); }
                // A form that answers with a file never leaves the page; don't leave the bar hanging.
                timer = setTimeout(stop, 20000);
            }, 150);
        }
        function stop() {
            clearTimeout(timer);
            var b = bar();
            if (b) { b.style.transition = 'none'; b.classList.remove('is-going'); b.offsetWidth; b.style.transition = ''; }
        }
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
            var a = e.target.closest && e.target.closest('a[href]');
            if (!a || a.target || a.hasAttribute('download')) { return; }
            var url = new URL(a.href, location.href);
            if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) { return; }
            if (/download|export|backup/i.test(url.pathname)) { return; }
            start();
        });
        document.addEventListener('submit', function (e) {
            if (!e.defaultPrevented && !(e.target.target)) { start(); }
        });
        window.addEventListener('pageshow', stop);
        window.addEventListener('pagehide', stop);
    })();
</script>
