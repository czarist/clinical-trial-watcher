<?php

declare(strict_types=1);

namespace App\Services\Auth;

final readonly class IssuedToken
{
    public function __construct(
        public string $accessToken,
        public int $expiresInSeconds,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'bearer',
            'expires_in' => $this->expiresInSeconds,
        ];
    }
}
