<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Identifier systems of clinical trial registries. Two records that carry the same normalized
 * identifier of one of these systems describe the same registered study.
 *
 * Sponsor protocol numbers and grant numbers are not listed: they are not unique across
 * sponsors and must never be used to merge studies.
 */
enum IdentifierScheme: string
{
    case Nct = 'nct';
    case EuCt = 'eu_ct';
    case EudraCt = 'eudract';
    case Isrctn = 'isrctn';
    case Drks = 'drks';
    case Actrn = 'actrn';
    case ChiCtr = 'chictr';
    case Jrct = 'jrct';
    case Umin = 'umin';
    case Cris = 'cris';
    case Ctri = 'ctri';
    case Rbr = 'rbr';
    case Pactr = 'pactr';
    case Utn = 'utn';

    /**
     * Pattern of a whole identifier, matched case-insensitively against a trimmed value.
     * The EU CT pattern is tried before EudraCT, whose shape is a prefix of it.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Nct => '/^NCT\s*-?\s*(\d{8})$/i',
            self::EuCt => '/^(?:EU\s*CT\s*(?:number|no\.?)?\s*:?\s*)?((?:19|20)\d{2})-(5\d{5})-(\d{2})-(\d{2})$/i',
            self::EudraCt => '/^(?:EudraCT\s*(?:number|no\.?)?\s*:?\s*)?((?:19|20)\d{2})-(\d{6})-(\d{2})(?:-[A-Z]{2})?$/i',
            self::Isrctn => '/^ISRCTN\s*-?\s*(\d{8})$/i',
            self::Drks => '/^DRKS\s*-?\s*(\d{8})$/i',
            self::Actrn => '/^ACTRN\s*-?\s*(\d{14})$/i',
            self::ChiCtr => '/^ChiCTR\s*-?\s*((?:[A-Z]{2,4}-)?\d{8,10})$/i',
            self::Jrct => '/^jRCT\s*-?\s*([a-z]?\d{9,10})$/i',
            self::Umin => '/^(?:UMIN|JPRN-UMIN)\s*-?\s*(\d{9})$/i',
            self::Cris => '/^KCT\s*-?\s*(\d{7})$/i',
            self::Ctri => '/^CTRI\s*\/\s*(\d{4})\s*\/\s*(\d{2,3})\s*\/\s*(\d{6})$/i',
            self::Rbr => '/^RBR\s*-\s*([0-9a-z]{6,8})$/i',
            self::Pactr => '/^PACTR\s*-?\s*(\d{15,16})$/i',
            self::Utn => '/^U\s*1111-(\d{4})-(\d{4})$/i',
        };
    }

    /**
     * The canonical spelling built from the pattern's captured groups.
     *
     * @param  list<string>  $groups
     */
    public function format(array $groups): string
    {
        return match ($this) {
            self::Nct => 'NCT'.$groups[0],
            self::EuCt => implode('-', $groups),
            self::EudraCt => implode('-', $groups),
            self::Isrctn => 'ISRCTN'.$groups[0],
            self::Drks => 'DRKS'.$groups[0],
            self::Actrn => 'ACTRN'.$groups[0],
            // ChiCTR2000012345, or the older ChiCTR-IOR-17012345 with a study type.
            self::ChiCtr => 'ChiCTR'.(ctype_digit($groups[0]) ? '' : '-').strtoupper($groups[0]),
            self::Jrct => 'jRCT'.strtolower($groups[0]),
            self::Umin => 'UMIN'.$groups[0],
            self::Cris => 'KCT'.$groups[0],
            self::Ctri => 'CTRI/'.implode('/', $groups),
            self::Rbr => 'RBR-'.strtolower($groups[0]),
            self::Pactr => 'PACTR'.$groups[0],
            self::Utn => 'U1111-'.implode('-', $groups),
        };
    }
}
