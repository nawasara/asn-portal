<?php

namespace Nawasara\AsnPortal\Services;

use Nawasara\AsnPortal\Models\PortalApp;
use Nawasara\Keycloak\Services\KeycloakClient;

/**
 * The employee realm, as the account portal needs it.
 *
 * Every method takes the Keycloak user id (= token `sub`) of the CALLER;
 * controllers never pass an id from the request. Ownership of sub-resources
 * (a credential, a session) is checked against this user's own list before
 * anything is changed.
 */
class KeycloakDirectory
{
    public const ATTR_WHATSAPP = 'whatsapp_number';

    public const ATTR_WHATSAPP_VERIFIED_AT = 'whatsapp_verified_at';

    protected ?KeycloakClient $client = null;

    public function client(): KeycloakClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $client = app(KeycloakClient::class);
        $realm = config('nawasara-asn-portal.keycloak.realm');

        // Null = the default client (Vault group `keycloak`), which is the
        // employee realm in every Nawasara install.
        if (is_string($realm) && $realm !== '') {
            $client = $client->forRealm($realm, config('nawasara-asn-portal.keycloak.vault_group') ?: null);
        }

        return $this->client = $client;
    }

    public function user(string $sub): ?array
    {
        return $this->client()->getUser($sub);
    }

    public function findByNip(string $nip): ?array
    {
        return $this->client()->findUserByUsername($nip);
    }

    /** @return list<array<string, mixed>> */
    public function credentials(string $sub): array
    {
        return $this->client()->getUserCredentials($sub);
    }

    public function deleteCredential(string $sub, string $credentialId): bool
    {
        return $this->client()->deleteUserCredential($sub, $credentialId);
    }

    public function renameCredential(string $sub, string $credentialId, string $label): bool
    {
        return $this->client()->setCredentialLabel($sub, $credentialId, $label);
    }

    /**
     * Remove every authenticator app (credential type `otp`) of a user, so
     * Keycloak asks them to set one up at the next sign-in. Same effect as
     * the old portal's KeycloakService::resetOtp().
     */
    public function resetAuthenticators(string $sub): bool
    {
        foreach ($this->credentials($sub) as $credential) {
            if (($credential['type'] ?? null) === 'otp' && is_string($credential['id'] ?? null)) {
                if (! $this->deleteCredential($sub, $credential['id'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function sessions(string $sub): array
    {
        return $this->client()->getUserSessions($sub);
    }

    public function deleteSession(string $sessionId): bool
    {
        return $this->client()->deleteSession($sessionId);
    }

    /** @return list<array<string, mixed>> */
    public function events(string $sub, int $days, int $max = 200): array
    {
        return $this->client()->getUserEvents($sub, [
            'dateFrom' => now()->subDays($days)->toDateString(),
            'max' => $max,
        ]);
    }

    /**
     * Merge attributes into the user and PUT the FULL representation back.
     *
     * Not KeycloakClient::setUserAttribute(), which PUTs only `attributes`:
     * with Keycloak 26's declarative user profile a partial representation
     * can clear or fail on the other profile fields. The old portal sent the
     * whole user for that reason, and it is the behaviour proven in
     * production.
     *
     * @param  array<string, ?string>  $attributes  null removes the attribute
     */
    public function setAttributes(string $sub, array $attributes): bool
    {
        $user = $this->user($sub);

        if ($user === null) {
            return false;
        }

        $current = $user['attributes'] ?? [];

        foreach ($attributes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = [$value];
            }
        }

        $user['attributes'] = $current;

        return $this->client()->updateUser($sub, $user);
    }

    public static function attribute(?array $user, string $key): ?string
    {
        $value = $user['attributes'][$key][0] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Keycloak client id → readable app name, from the catalog.
     *
     * @return array<string, string>
     */
    public function appNames(): array
    {
        return PortalApp::query()
            ->whereNotNull('keycloak_client_id')
            ->pluck('name', 'keycloak_client_id')
            ->all();
    }
}
