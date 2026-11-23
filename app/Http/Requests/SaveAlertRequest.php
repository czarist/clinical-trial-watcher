<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertFrequency;
use App\Models\Alert;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates an alert (POST, all fields) or changes one (PATCH, only the fields sent).
 */
final class SaveAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alert = $this->route('alert');

        return $alert instanceof Alert ? $this->user()?->can('update', $alert) ?? false : true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'criteria' => [$required, 'array'],
            ...TrialSearchCriteria::rules('criteria.'),
            'frequency' => [$required, Rule::enum(AlertFrequency::class)],
            'notify_status_changes' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * An alert without any filter would match every study.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('criteria') && $this->criteria()?->isEmpty()) {
                    $validator->errors()->add('criteria', 'Choose at least one filter for the alert.');
                }
            },
        ];
    }

    public function criteria(): ?TrialSearchCriteria
    {
        $criteria = $this->input('criteria');

        return is_array($criteria) ? TrialSearchCriteria::fromArray($criteria) : null;
    }

    /**
     * @return array{name?: string, criteria?: TrialSearchCriteria, frequency?: AlertFrequency, notify_status_changes?: bool, is_active?: bool}
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = array_intersect_key($validated, array_flip(['name', 'notify_status_changes', 'is_active']));

        if (isset($validated['frequency'])) {
            $changes['frequency'] = AlertFrequency::from($validated['frequency']);
        }

        if (isset($validated['criteria'])) {
            $changes['criteria'] = TrialSearchCriteria::fromArray($validated['criteria']);
        }

        return $changes;
    }
}
