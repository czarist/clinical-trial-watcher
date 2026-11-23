<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AlertFrequency;
use App\Enums\DigestFrequency;
use App\Enums\DigestLanguage;
use App\Models\Alert;
use App\Models\Team;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creates a saved search (POST, all fields) or changes one (PATCH, only the fields sent).
 * A POST with team_id creates a team's shared saved search and is allowed to the team owner.
 */
final class SaveAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $alert = $this->route('alert');

        if ($alert instanceof Alert) {
            return $user?->can('update', $alert) ?? false;
        }

        $team = $this->team();

        return $team === null || ($user?->can('manage', $team) ?? false);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'team_id' => $this->isMethod('POST') ? ['sometimes', 'nullable', 'integer', 'exists:teams,id'] : ['prohibited'],
            'name' => [$required, 'string', 'max:120'],
            'criteria' => [$required, 'array'],
            ...TrialSearchCriteria::rules('criteria.'),
            'frequency' => [$required, Rule::enum(AlertFrequency::class)],
            'notify_status_changes' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'ai_digest_enabled' => ['sometimes', 'boolean'],
            'ai_digest_frequency' => ['sometimes', Rule::enum(DigestFrequency::class)],
            'ai_digest_language' => ['sometimes', Rule::enum(DigestLanguage::class)],
        ];
    }

    /**
     * A saved search without any filter would match every study.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->has('criteria') && $this->criteria()?->isEmpty()) {
                    $validator->errors()->add('criteria', 'Choose at least one filter for the saved search.');
                }
            },
        ];
    }

    public function team(): ?Team
    {
        $teamId = $this->input('team_id');

        return is_numeric($teamId) ? Team::query()->find((int) $teamId) : null;
    }

    public function criteria(): ?TrialSearchCriteria
    {
        $criteria = $this->input('criteria');

        return is_array($criteria) ? TrialSearchCriteria::fromArray($criteria) : null;
    }

    /**
     * @return array<string, mixed> validated fields, with enums and criteria as objects
     */
    public function changes(): array
    {
        $validated = $this->validated();
        $changes = array_intersect_key($validated, array_flip(['name', 'notify_status_changes', 'is_active', 'ai_digest_enabled']));

        if (isset($validated['frequency'])) {
            $changes['frequency'] = AlertFrequency::from($validated['frequency']);
        }

        if (isset($validated['ai_digest_frequency'])) {
            $changes['ai_digest_frequency'] = DigestFrequency::from($validated['ai_digest_frequency']);
        }

        if (isset($validated['ai_digest_language'])) {
            $changes['ai_digest_language'] = DigestLanguage::from($validated['ai_digest_language']);
        }

        if (isset($validated['criteria'])) {
            $changes['criteria'] = TrialSearchCriteria::fromArray($validated['criteria']);
        }

        if (isset($changes['ai_digest_enabled'])) {
            $changes['ai_digest_enabled'] = (bool) $changes['ai_digest_enabled'];
        }

        return $changes;
    }
}
