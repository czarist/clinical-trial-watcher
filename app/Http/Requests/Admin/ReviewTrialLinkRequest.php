<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TrialLinkStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewTrialLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TrialLinkStatus::class)->only([TrialLinkStatus::Confirmed, TrialLinkStatus::Rejected])],
        ];
    }

    public function decision(): TrialLinkStatus
    {
        return TrialLinkStatus::from($this->string('status')->toString());
    }
}
