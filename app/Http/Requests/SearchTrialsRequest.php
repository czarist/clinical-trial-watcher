<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use App\Services\ClinicalTrials\Search\TrialSearchService;
use App\Services\ClinicalTrials\Search\TrialSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchTrialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...TrialSearchCriteria::rules(),
            'sort' => ['nullable', Rule::enum(TrialSort::class)],
            'per_page' => ['nullable', 'integer', 'between:1,'.TrialSearchService::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function criteria(): TrialSearchCriteria
    {
        return TrialSearchCriteria::fromArray($this->validated());
    }

    public function sort(): TrialSort
    {
        return TrialSort::tryFrom((string) $this->validated('sort')) ?? TrialSort::LastUpdateDesc;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
