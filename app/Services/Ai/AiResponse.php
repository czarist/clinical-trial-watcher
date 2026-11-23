<?php

declare(strict_types=1);

namespace App\Services\Ai;

final readonly class AiResponse
{
    /**
     * @param  string  $content  the model's message text (expected to be a JSON object)
     */
    public function __construct(
        public string $content,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}
