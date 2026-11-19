<?php

namespace Nawasara\AsnPortal\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone's access to a Kisara app, or that app's kill switch / open-to-all
 * setting, changed. Fired after the change is saved.
 *
 * A Kisara app that keeps its own copy of the list listens to this.
 * nawasara/workspace does, to bring the Keycloak group `workspace-pilot` in
 * line within a minute; it listens by class NAME, so neither package has to
 * require the other.
 */
class KisaraAccessChanged
{
    use Dispatchable;

    public function __construct(public readonly string $app) {}
}
