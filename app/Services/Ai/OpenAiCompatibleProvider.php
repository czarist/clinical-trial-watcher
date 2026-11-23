<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Chat completions on any OpenAI-compatible API, asking for a JSON object response.
 */
final readonly class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private HttpFactory $http,
        private bool $enabled,
        private string $baseUrl,
        private ?string $apiKey,
        private ?string $modelName,
        private int $timeout,
    ) {}

    #[\Override]
    public function isAvailable(): bool
    {
        return $this->enabled && $this->baseUrl !== '' && ($this->apiKey ?? '') !== '' && ($this->modelName ?? '') !== '';
    }

    #[\Override]
    public function model(): ?string
    {
        return $this->modelName;
    }

    #[\Override]
    public function completeJson(string $system, string $user, int $maxOutputTokens): AiResponse
    {
        if (! $this->isAvailable()) {
            throw AiProviderFailed::unavailable();
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->timeout($this->timeout)
                ->post('/chat/completions', [
                    'model' => $this->modelName,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                    'max_tokens' => $maxOutputTokens,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (ConnectionException $exception) {
            throw AiProviderFailed::connection($exception->getMessage());
        }

        if ($response->failed()) {
            throw AiProviderFailed::httpStatus($response->status());
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw AiProviderFailed::emptyContent();
        }

        $input = $response->json('usage.prompt_tokens');
        $output = $response->json('usage.completion_tokens');

        return new AiResponse(
            $content,
            is_int($input) ? $input : null,
            is_int($output) ? $output : null,
        );
    }
}
