<?php

return [

    /*
    | The Keycloak client of the ASN account portal (realm asn-productions).
    |
    | Account routes accept ONLY tokens whose `azp` is this client
    | (api.client middleware). Empty = every account request is rejected:
    | an unconfigured portal must stay closed, not open to every application
    | in the employee realm.
    |
    | The same client must also be listed in NAWASARA_STAFF_KC_CLIENTS,
    | otherwise the token is rejected one step earlier by the verifier.
    */
    'client_id' => env('ASN_PORTAL_KC_CLIENT', ''),

    /*
    | How fresh a login must be (seconds since `auth_time`) before an action
    | that changes sign-in methods or reveals a secret is allowed. Older
    | logins get `max_age=0` added to the Keycloak redirect, which forces the
    | user to sign in again on the way.
    */
    'recent_auth_seconds' => (int) env('ASN_PORTAL_RECENT_AUTH_SECONDS', 300),

    // Requests per minute per signed-in user (keyed on `sub`, not IP:
    // a whole office sits behind one public address).
    'rate_limit_per_minute' => (int) env('ASN_PORTAL_RATE_PER_MINUTE', 60),

    // Requests per minute per IP on the public endpoints (guest pages).
    'public_rate_limit_per_minute' => (int) env('ASN_PORTAL_PUBLIC_RATE_PER_MINUTE', 120),

];
