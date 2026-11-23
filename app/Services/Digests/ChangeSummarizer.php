<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Enums\DigestLanguage;
use App\Models\ChangeSummary;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFailed;
use App\Services\Ai\AiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Factual summaries of changes, shared by every subscriber.
 *
 * A summary is cached per change version (its fingerprint), language and prompt version, so
 * a change described in many digests is sent to the model once. Missing summaries are asked
 * for in batches bounded by the input size, with bounded retries on provider failures. Output
 * that fails validation is discarded, and those changes get the factual summary built from
 * the recorded details.
 */
final readonly class ChangeSummarizer
{
    private const int MAX_TITLE_CHARS = 300;

    public function __construct(
        private AiProvider $provider,
        private FactualChangeWriter $writer,
        private SummaryPrompt $prompt,
        private SummaryValidator $validator,
        private string $promptVersion,
        private int $maxInputChars,
        private int $maxOutputTokens,
        private int $retries,
        private int $retrySleepMs,
    ) {}

    public function cacheKey(ClinicalTrialChange $change, DigestLanguage $language): string
    {
        return hash('sha256', implode('|', [$change->fingerprint ?? 'change:'.$change->id, $language->value, $this->promptVersion]));
    }

    /**
     * @param  list<ClinicalTrialChange>  $changes  with their trial loaded
     */
    public function summarize(array $changes, DigestLanguage $language): SummaryBatch
    {
        $keys = [];

        foreach ($changes as $change) {
            $keys[$change->id] = $this->cacheKey($change, $language);
        }

        $cached = ChangeSummary::query()->whereIn('cache_key', array_values($keys))->get()->keyBy('cache_key');
        $results = [];
        $missing = [];

        foreach ($changes as $change) {
            $summary = $cached->get($keys[$change->id]);

            if ($summary instanceof ChangeSummary) {
                $results[$change->id] = new SummaryResult($summary->summary, SummaryResult::AI, $summary->id);
            } else {
                $missing[] = $change;
            }
        }

        $cacheHits = count($results);
        $generated = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $fallbackReason = null;

        if ($missing !== [] && ! $this->provider->isAvailable()) {
            $fallbackReason = 'ai_disabled';
        } elseif ($missing !== []) {
            foreach ($this->batches($missing, $language) as $batch) {
                try {
                    $response = $this->complete($batch['items'], $language);
                } catch (AiProviderFailed $exception) {
                    Log::warning('digests.ai_provider_failed', ['reason' => $exception->getMessage()]);
                    $fallbackReason = 'provider_error';

                    break;
                }

                $inputTokens += $response->inputTokens ?? 0;
                $outputTokens += $response->outputTokens ?? 0;
                $accepted = $this->validator->accepted($response->content, $batch['nct_ids']);

                if (count($accepted) < count($batch['changes'])) {
                    $fallbackReason ??= 'invalid_output';
                    Log::warning('digests.ai_output_rejected', ['requested' => count($batch['changes']), 'accepted' => count($accepted)]);
                }

                foreach ($batch['changes'] as $change) {
                    if (! isset($accepted[$change->id])) {
                        continue;
                    }

                    $summary = ChangeSummary::query()->firstOrCreate(['cache_key' => $keys[$change->id]], [
                        'clinical_trial_change_id' => $change->id,
                        'language' => $language->value,
                        'prompt_version' => $this->promptVersion,
                        'model' => $this->provider->model(),
                        'summary' => $accepted[$change->id],
                    ]);
                    $results[$change->id] = new SummaryResult($summary->summary, SummaryResult::AI, $summary->id);
                    $generated++;
                }
            }
        }

        $fallbacks = 0;

        foreach ($changes as $change) {
            if (! isset($results[$change->id])) {
                $results[$change->id] = new SummaryResult($this->writer->summary($change, $language), SummaryResult::FACTUAL);
                $fallbacks++;
            }
        }

        return new SummaryBatch($results, $cacheHits, $generated, $fallbacks, $inputTokens, $outputTokens, $fallbacks > 0 ? ($fallbackReason ?? 'invalid_output') : null);
    }

    /**
     * Splits the changes into requests whose input stays under the configured size.
     *
     * @param  list<ClinicalTrialChange>  $changes
     * @return list<array{changes: list<ClinicalTrialChange>, items: list<array<string, mixed>>, nct_ids: array<int, string>}>
     */
    private function batches(array $changes, DigestLanguage $language): array
    {
        $batches = [];
        $current = ['changes' => [], 'items' => [], 'nct_ids' => []];
        $size = 0;

        foreach ($changes as $change) {
            $nctId = (string) $change->trial?->nct_id;

            if (! RegistryLink::isValidNctId($nctId)) {
                continue;
            }

            $item = [
                'change_id' => $change->id,
                'nct_id' => $nctId,
                'title' => Str::limit((string) $change->trial?->brief_title, self::MAX_TITLE_CHARS),
                'facts' => $this->writer->facts($change, $language),
            ];
            $itemSize = strlen(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            // A single item larger than the budget is not sent; it gets the factual summary.
            if ($itemSize > $this->maxInputChars) {
                continue;
            }

            if ($size + $itemSize > $this->maxInputChars && $current['items'] !== []) {
                $batches[] = $current;
                $current = ['changes' => [], 'items' => [], 'nct_ids' => []];
                $size = 0;
            }

            $current['changes'][] = $change;
            $current['items'][] = $item;
            $current['nct_ids'][$change->id] = $nctId;
            $size += $itemSize;
        }

        if ($current['items'] !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function complete(array $items, DigestLanguage $language): AiResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->provider->completeJson($this->prompt->system($language), $this->prompt->user($items), $this->maxOutputTokens);
            } catch (AiProviderFailed $exception) {
                if (++$attempt > $this->retries) {
                    throw $exception;
                }

                Sleep::for($this->retrySleepMs * $attempt)->milliseconds();
            }
        }
    }
}
