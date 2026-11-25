<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allow private / loopback / link-local targets
    |--------------------------------------------------------------------------
    |
    | Postman lets you call local APIs, so by default Postmen does too:
    | when this is true, /send and the Collection Runner accept localhost,
    | *.test / *.local hosts and private IPs (e.g. http://allsolution.test
    | or http://192.168.1.10:8000). That is what a local dev install needs.
    |
    | On a public deployment set ALLOW_PRIVATE_IPS=false: the SSRF guard
    | then refuses private, loopback and link-local addresses again, so a
    | saved request cannot be used to probe the server's own network.
    |
    | The cloud metadata endpoint (169.254.169.254, whole 169.254.0.0/16
    | link-local range) is ALWAYS blocked, even when this is true.
    |
    */
    'allow_private_ips' => env('ALLOW_PRIVATE_IPS', true),
];
