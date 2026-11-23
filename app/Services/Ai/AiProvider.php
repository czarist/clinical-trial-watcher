<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * A language model that returns a JSON object for a system and a user message.
 */
interface AiProvider
{
    public function isAvailable(): bool;

    /**
     * @throws AiProviderFailed when the request fails or the response has no JSON content
     */
    public function completeJson(string $system, string $user, int $maxOutputTokens): AiResponse;

    /**
     * Model name recorded with generated summaries.
     */
    public function model(): ?string;
}
