<?php
namespace App\Services;

use Tymon\JWTAuth\Facades\JWTAuth;
use \Illuminate\Contracts\Auth\Authenticatable;

class AuthService
{
    public function login(array $credentials): array
    {
        if (! $token = auth('api')->attempt($credentials)) {
            return [
                'error'       => 'Unauthorized',
                'status_code' => 401,
            ];
        }

        return [
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => JWTAuth::factory()->getTTL() * 60,
            'status_code'  => 200,
        ];
    }

    public function logout(): array
    {
        auth('api')->logout();
        return ['message' => 'Successfully logged out'];
    }

    public function me(): ?Authenticatable
    {
        return auth('api')->user();
    }
}
