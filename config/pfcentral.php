<?php

return [
    // Seconds PFCentral keeps a firewall's configuration reads, so moving between pages
    // doesn't wait on the firewall again. Any change made through PFCentral clears it. 0 = off.
    'api_cache_seconds' => (int) env('API_CACHE_SECONDS', 15),
];
