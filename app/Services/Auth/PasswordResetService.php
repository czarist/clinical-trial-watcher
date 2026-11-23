<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PasswordResetService
{
    public function __construct(
        private PasswordBroker $broker,
        private Dispatcher $events,
    ) {}

    /**
     * Sends a reset link when the account exists. The caller always gets the same answer,
     * so the endpoint does not reveal which emails are registered.
     */
    public function sendResetLink(string $email): void
    {
        $this->broker->sendResetLink(['email' => $email]);
    }

    /**
     * @param  array{email: string, password: string, token: string}  $data
     *
     * @throws ValidationException when the token is invalid or expired
     */
    public function reset(array $data): void
    {
        $status = $this->broker->reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $this->events->dispatch(new PasswordReset($user));
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }
    }
}
