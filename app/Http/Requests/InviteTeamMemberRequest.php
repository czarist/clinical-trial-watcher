<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Invites a registered user to a team by email. Only the team owner can invite.
 */
final class InviteTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', $this->route('team')) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'exists:users,email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.exists' => 'Only people who already have an account can be invited. Ask them to sign up first.',
        ];
    }

    public function invitee(): User
    {
        return User::query()->where('email', $this->validated('email'))->firstOrFail();
    }
}
