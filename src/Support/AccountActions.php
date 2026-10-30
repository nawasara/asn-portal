<?php

namespace Nawasara\AsnPortal\Support;

/**
 * Which Keycloak required actions the portal may start, and the extra
 * authorize parameters each one needs.
 *
 * The portal is a confidential OIDC client: it builds the authorize request
 * itself, because only it holds the `state` and PKCE verifier of that
 * request. What it must NOT do is decide which `kc_action` names exist or
 * when a fresh login is required. That policy lives here, so changing a
 * Keycloak action name or the freshness rule is a Nawasara release, not a
 * portal release.
 *
 * Response shape returned to the portal:
 *
 *     { "action": "passkey", "authorize_params": { "kc_action": "...", "max_age": 0 } }
 *
 * The portal merges `authorize_params` into its own authorize request.
 */
final class AccountActions
{
    /** Portal-facing names → Keycloak required action ids. */
    public const ACTIONS = [
        'passkey' => 'webauthn-register-passwordless',
        'totp' => 'CONFIGURE_TOTP',
        'backup-codes' => 'CONFIGURE_RECOVERY_AUTHN_CODES',
    ];

    public static function supports(string $action, bool $backupCodes = true): bool
    {
        if ($action === 'backup-codes' && ! $backupCodes) {
            return false;
        }

        return array_key_exists($action, self::ACTIONS);
    }

    /**
     * Authorize parameters for a credential action.
     *
     * When the login is older than the freshness window, `max_age=0` is added
     * so Keycloak asks for the credentials again before the action, in the
     * same redirect. One redirect instead of two (reauth, then action), and
     * the user never sees a "please sign in again" error first.
     *
     * @return array<string, string|int>
     */
    public static function authorizeParams(string $action, bool $loginIsRecent): array
    {
        if (! self::supports($action)) {
            throw new \InvalidArgumentException("Unsupported account action: {$action}");
        }

        $params = ['kc_action' => self::ACTIONS[$action]];

        if (! $loginIsRecent) {
            $params['max_age'] = 0;
        }

        return $params;
    }

    /**
     * Authorize parameters to force a fresh login only (no action), used
     * when an API call answered `reauth_required`.
     *
     * @return array<string, int>
     */
    public static function reauthParams(): array
    {
        return ['max_age' => 0];
    }
}
