<?php
namespace App\Services;

use Tymon\JWTAuth\Facades\JWTAuth;
use \Illuminate\Contracts\Auth\Authenticatable;
use \Tymon\JWTAuth\Exceptions\JWTException;

class AuthService
{
    public function login(array $credentials): array
    {
        try {
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
        } catch (JWTException $e) {
            return [
                'error'       => 'Could not create token',
                'message'     => $e->getMessage(),
                'status_code' => 500,
            ];
        }
    }

    public function logout(): array
    {
        try {
            auth('api')->logout();
            return ['message' => 'Successfully logged out', 'status_code' => 200];
        } catch (JWTException $e) {
            return ['error' => $e->getMessage(), 'status_code' => 500];
        }
    }

    public function me(): ?Authenticatable
    {
        return auth('api')->user();
    }
}
