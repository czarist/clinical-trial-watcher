<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClinicalTrials\Import\RegistryDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryDateTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function dates(): iterable
    {
        yield 'full date' => ['2024-06-15', '2024-06-15'];
        yield 'year and month' => ['2024-06', '2024-06-01'];
        yield 'year only' => ['2024', '2024-01-01'];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'unexpected format' => ['June 2024', null];
        yield 'not a string' => [2024, null];
    }

    #[DataProvider('dates')]
    public function test_it_normalizes_registry_dates(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, RegistryDate::normalize($input));
    }
}
