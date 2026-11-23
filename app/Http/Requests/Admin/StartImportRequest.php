<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\ClinicalTrials\Import\ImportOptions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class StartImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'full' => ['sometimes', 'boolean'],
            'since' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'page_size' => ['sometimes', 'integer', 'between:1,1000'],
            'max_pages' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function options(): ImportOptions
    {
        $since = $this->validated('since');
        $maxPages = $this->validated('max_pages');

        return new ImportOptions(
            pageSize: (int) ($this->validated('page_size') ?? config('clinicaltrials.page_size')),
            full: (bool) ($this->validated('full') ?? false),
            updatedSince: $since !== null ? CarbonImmutable::parse($since) : null,
            maxPages: $maxPages !== null ? (int) $maxPages : null,
        );
    }
}
