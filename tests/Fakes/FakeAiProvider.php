<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFailed;
use App\Services\Ai\AiResponse;
use Closure;

/**
 * Answers in place of the model. By default it returns one valid summary per requested change.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<array{system: string, user: string}> */
    public array $requests = [];

    /**
     * @param  (Closure(array<string, mixed>): (AiResponse|AiProviderFailed))|null  $respond  receives the decoded user input
     */
    public function __construct(
        public bool $available = true,
        public ?Closure $respond = null,
    ) {}

    #[\Override]
    public function isAvailable(): bool
    {
        return $this->available;
    }

    #[\Override]
    public function model(): ?string
    {
        return 'fake-model';
    }

    #[\Override]
    public function completeJson(string $system, string $user, int $maxOutputTokens): AiResponse
    {
        $this->requests[] = ['system' => $system, 'user' => $user];
        $input = json_decode($user, true, flags: JSON_THROW_ON_ERROR);
        $result = $this->respond !== null ? ($this->respond)($input) : self::summariesFor($input);

        if ($result instanceof AiProviderFailed) {
            throw $result;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function summariesFor(array $input, string $text = 'The registry entry changed to Recruiting.'): AiResponse
    {
        return new AiResponse(json_encode(['summaries' => array_map(
            fn (array $change): array => ['change_id' => $change['change_id'], 'summary' => "{$change['nct_id']}: {$text}"],
            $input['changes'],
        )], JSON_THROW_ON_ERROR), 120, 40);
    }
}
