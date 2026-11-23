<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['sometimes', 'string', Rule::enum(Locale::class)],
        ];
    }

    /**
     * The language chosen in the web app, or the one the request was answered in.
     */
    public function locale(): Locale
    {
        return $this->filled('locale')
            ? Locale::from($this->string('locale')->toString())
            : (Locale::tryFrom(app()->getLocale()) ?? Locale::default());
    }
}
