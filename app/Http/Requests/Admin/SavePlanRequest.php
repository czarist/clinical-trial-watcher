<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePlanRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $plan = $this->route('plan');
        $ignore = $plan instanceof Plan ? $plan->id : null;

        return [
            'name' => [$required, 'string', 'max:100', Rule::unique('plans', 'name')->ignore($ignore)],
            'price' => [$required, 'numeric', 'min:0', 'max:999999.99'],
            'billing_cycle' => [$required, Rule::in(['monthly', 'yearly'])],
            'stripe_price_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('plans', 'stripe_price_id')->ignore($ignore)],
            'trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'alerts_limit' => [$required, 'integer', 'between:0,100000'],
        ];
    }
}
