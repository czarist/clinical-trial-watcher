<?php

declare(strict_types=1);

namespace App\Services\Registries\Identifiers;

use App\Enums\IdentifierScheme;
use App\Services\ClinicalTrials\Import\MappedStudy;

/**
 * Recognizes registry identifiers in the values registries publish ("ISRCTN 12345678",
 * "EudraCT Number: 2019-001234-12-DE", "nct01234567") and writes them one way, so the same
 * identifier always compares equal. Values that are not a known registry identifier, such as
 * sponsor protocol numbers, are ignored rather than guessed.
 */
final class IdentifierParser
{
    /** Secondary identifier types whose value is an EudraCT number even when spelled loosely. */
    private const array EUDRACT_TYPES = ['EUDRACT_NUMBER', 'EUDRACT'];

    public function parse(?string $raw, ?string $type = null, bool $isPrimary = false): ?TrialIdentifier
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');

        if ($value === '' || mb_strlen($value) > 100) {
            return null;
        }

        foreach (IdentifierScheme::cases() as $scheme) {
            if (preg_match($scheme->pattern(), $value, $matches) === 1) {
                return new TrialIdentifier($scheme, $scheme->format(array_values(array_slice($matches, 1))), $isPrimary);
            }
        }

        // An EudraCT number given without its usual prefix and with stray characters.
        if ($type !== null && in_array(strtoupper($type), self::EUDRACT_TYPES, true)
            && preg_match('/((?:19|20)\d{2})-(\d{6})-(\d{2})/', $value, $matches) === 1) {
            return new TrialIdentifier(IdentifierScheme::EudraCt, implode('-', array_slice($matches, 1)), $isPrimary);
        }

        return null;
    }

    /**
     * Every identifier of a mapped study: its own (primary) one and those it cites in its
     * secondary identifiers and organization study id. Duplicates are removed; a primary
     * identifier wins over the same value cited as secondary.
     *
     * @return list<TrialIdentifier>
     */
    public function fromStudy(MappedStudy $study): array
    {
        $found = [];
        $add = function (?TrialIdentifier $identifier) use (&$found): void {
            if ($identifier !== null && (! isset($found[$identifier->key()]) || $identifier->isPrimary)) {
                $found[$identifier->key()] = $identifier;
            }
        };

        $add($this->parse($study->sourceId, isPrimary: true));

        foreach ($study->relations['secondaryIds'] ?? [] as $row) {
            $add($this->parse($row['secondary_id'] ?? null, $row['type'] ?? null));
        }

        $add($this->parse($study->attributes['org_study_id'] ?? null));

        return array_values($found);
    }
}
