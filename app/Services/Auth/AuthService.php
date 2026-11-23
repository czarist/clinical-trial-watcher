<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RoleName;
use App\Exceptions\InvalidCredentials;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\PlanResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Tymon\JWTAuth\JWTGuard;

/**
 * Account registration and JWT token lifecycle for the API guard.
 */
final readonly class AuthService
{
    public function __construct(
        private AuthFactory $auth,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private PlanResolver $plans,
    ) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws InvalidCredentials
     */
    public function login(array $credentials): IssuedToken
    {
        $token = $this->guard()->attempt($credentials);

        if (! is_string($token)) {
            throw new InvalidCredentials;
        }

        return $this->issued($token);
    }

    /**
     * Creates a regular account on the Free plan (no trial) and sends the email verification link.
     *
     * @return array{0: User, 1: IssuedToken}
     */
    public function register(string $name, string $email, string $password): array
    {
        $user = $this->db->transaction(function () use ($name, $email, $password): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'plan_id' => $this->plans->freePlan()?->id,
            ]);

            $role = Role::query()->where('name', RoleName::User->value)->first();

            if ($role !== null) {
                $user->roles()->attach($role);
            }

            return $user;
        });

        $this->events->dispatch(new Registered($user));

        return [$user, $this->issued($this->guard()->login($user))];
    }

    public function refresh(): IssuedToken
    {
        return $this->issued($this->guard()->refresh());
    }

    public function logout(): void
    {
        $this->guard()->logout();
    }

    private function issued(string $token): IssuedToken
    {
        return new IssuedToken($token, $this->guard()->factory()->getTTL() * 60);
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard */
        return $this->auth->guard('api');
    }
}
