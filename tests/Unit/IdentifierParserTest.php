<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IdentifierScheme;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\Registries\Identifiers\IdentifierParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, ?IdentifierScheme, ?string}>
     */
    public static function values(): iterable
    {
        yield 'NCT, lower case' => ['nct01234567', null, IdentifierScheme::Nct, 'NCT01234567'];
        yield 'NCT with a space' => ['NCT 01234567', null, IdentifierScheme::Nct, 'NCT01234567'];
        yield 'ISRCTN with a space' => ['ISRCTN 12345678', null, IdentifierScheme::Isrctn, 'ISRCTN12345678'];
        yield 'EudraCT with prefix and country suffix' => ['EudraCT Number: 2019-001234-12-DE', null, IdentifierScheme::EudraCt, '2019-001234-12'];
        yield 'EudraCT typed loosely' => ['EUDRACT#2019-001234-12', 'EUDRACT_NUMBER', IdentifierScheme::EudraCt, '2019-001234-12'];
        yield 'EU CT number' => ['2023-503456-21-00', null, IdentifierScheme::EuCt, '2023-503456-21-00'];
        yield 'DRKS' => ['drks00012345', null, IdentifierScheme::Drks, 'DRKS00012345'];
        yield 'ACTRN' => ['ACTRN12620000123456', null, IdentifierScheme::Actrn, 'ACTRN12620000123456'];
        yield 'ChiCTR with type' => ['ChiCTR-IOR-17012345', null, IdentifierScheme::ChiCtr, 'ChiCTR-IOR-17012345'];
        yield 'ChiCTR, current form' => ['chictr2000012345', null, IdentifierScheme::ChiCtr, 'ChiCTR2000012345'];
        yield 'jRCT' => ['JRCTS031180123', null, IdentifierScheme::Jrct, 'jRCTs031180123'];
        yield 'UMIN, WHO form' => ['JPRN-UMIN000012345', null, IdentifierScheme::Umin, 'UMIN000012345'];
        yield 'CRIS' => ['KCT0001234', null, IdentifierScheme::Cris, 'KCT0001234'];
        yield 'CTRI' => ['CTRI/2020/05/025123', null, IdentifierScheme::Ctri, 'CTRI/2020/05/025123'];
        yield 'ReBEC' => ['RBR-7XJ3K9', null, IdentifierScheme::Rbr, 'RBR-7xj3k9'];
        yield 'UTN' => ['U1111-1234-5678', null, IdentifierScheme::Utn, 'U1111-1234-5678'];
        yield 'sponsor protocol number is ignored' => ['ABC-123-2020', 'OTHER', null, null];
        yield 'a grant number is ignored' => ['R01 HL123456', 'NIH', null, null];
        yield 'empty' => ['  ', null, null, null];
    }

    #[DataProvider('values')]
    public function test_registry_identifiers_are_recognized_and_normalized(string $raw, ?string $type, ?IdentifierScheme $scheme, ?string $value): void
    {
        $identifier = (new IdentifierParser)->parse($raw, $type);

        $this->assertSame($scheme, $identifier?->scheme);
        $this->assertSame($value, $identifier?->value);
    }

    public function test_a_study_lists_its_own_identifier_as_primary_and_each_cited_one_once(): void
    {
        $study = new MappedStudy(
            source: 'clinicaltrials_gov',
            sourceId: 'NCT01234567',
            attributes: ['org_study_id' => 'ISRCTN12345678'],
            relations: ['secondaryIds' => [
                ['secondary_id' => 'ISRCTN 12345678', 'type' => 'REGISTRY'],
                ['secondary_id' => 'nct01234567', 'type' => 'OTHER'],
                ['secondary_id' => 'PROTOCOL-7', 'type' => 'OTHER'],
            ]],
        );

        $identifiers = (new IdentifierParser)->fromStudy($study);

        $this->assertSame(['nct:NCT01234567', 'isrctn:ISRCTN12345678'], array_map(fn ($i) => $i->key(), $identifiers));
        $this->assertTrue($identifiers[0]->isPrimary);
        $this->assertFalse($identifiers[1]->isPrimary);
    }
}
