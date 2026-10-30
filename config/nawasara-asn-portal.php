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

    // MFA reset requests per minute per REAL client IP (X-Portal-Client-IP),
    // on top of the per-NIP code limits below.
    'mfa_reset_rate_per_minute' => (int) env('ASN_PORTAL_MFA_RESET_RATE_PER_MINUTE', 10),

    /*
    | Employee realm. Null = the default KeycloakClient (Vault group
    | `keycloak`), which is the employee realm in every Nawasara install.
    */
    'keycloak' => [
        'realm' => env('ASN_PORTAL_KC_REALM'),
        'vault_group' => env('ASN_PORTAL_KC_VAULT_GROUP'),
    ],

    // One-time codes (MFA reset, WhatsApp verification). See OtpChallenge.
    'otp' => [
        'ttl_minutes' => (int) env('ASN_PORTAL_OTP_TTL_MINUTES', 15),
        'max_sends' => (int) env('ASN_PORTAL_OTP_MAX_SENDS', 3),
        'send_window_minutes' => (int) env('ASN_PORTAL_OTP_SEND_WINDOW_MINUTES', 10),
        'max_verify_attempts' => (int) env('ASN_PORTAL_OTP_MAX_VERIFY_ATTEMPTS', 5),
    ],

    'whatsapp' => [
        // OFF by default: production has run without WhatsApp verification
        // since the WAGO number was banned. See WhatsappController.
        'verification_enabled' => (bool) env('ASN_PORTAL_WA_VERIFICATION', false),

        // Vault group holding the WAGO gateway (fields api_base, api_key).
        'vault_group' => env('ASN_PORTAL_WA_VAULT_GROUP', 'whatsapp'),
    ],

    'mail' => [
        // Forwarders from an official mailbox may only point at these
        // domains (and their subdomains). See ForwardingPolicy for why.
        'forward_allowed_domains' => array_filter(array_map('trim', explode(',', env('ASN_PORTAL_MAIL_FORWARD_DOMAINS', 'ponorogo.go.id')))),
    ],

    // How far back "Aktivitas" looks. Must not exceed what the Keycloak
    // realm keeps (events expiration) and what the privacy policy promises.
    'activity_days' => (int) env('ASN_PORTAL_ACTIVITY_DAYS', 90),

];
