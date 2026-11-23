<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an operator has to do before a registry's data can be used fully.
 */
enum RegistryAccessStatus: string
{
    /** Imported under its published terms; nothing to do. */
    case Active = 'active';

    /** Imported under its published terms; a written confirmation is advisable. */
    case ConfirmationAdvised = 'confirmation_advised';

    /** Imported and shown for free, but kept out of AI digests until the registry allows paid use. */
    case PaidUseUnconfirmed = 'paid_use_unconfirmed';

    /** The importer is ready but needs an operator key. */
    case NeedsKey = 'needs_key';

    /** The importer is ready but every request is refused until the registry's written permission is recorded. */
    case NeedsPermission = 'needs_permission';
}
