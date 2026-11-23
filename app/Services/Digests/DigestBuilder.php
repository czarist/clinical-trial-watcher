<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Enums\DigestGeneration;
use App\Enums\DigestStatus;
use App\Models\Alert;
use App\Models\AlertMatch;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Models\Digest;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use App\Services\Registries\SourceRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;

/**
 * Builds a digest's content from the changes that matched its saved search in the window.
 *
 * The backend counts and ranks the changes and attaches the reasons, links and facts; the
 * model (when available) only words the factual summary. The content is stored once and
 * reused by the email and the report page.
 */
final readonly class DigestBuilder
{
    /** Most recent changes considered for ranking in one window; the total is still counted. */
    private const int MAX_RANKED = 1000;

    public function __construct(
        private DigestRanker $ranker,
        private ChangeSummarizer $summarizer,
        private FactualChangeWriter $writer,
        private ConnectionInterface $db,
        private SourceRegistry $sources,
        private int $maxItems,
    ) {}

    public function build(Digest $digest, Alert $alert): void
    {
        $startedAt = microtime(true);
        $language = $digest->language;
        $locale = $language->value;

        $changes = ClinicalTrialChange::query()
            ->whereIn('id', AlertMatch::query()->where('alert_id', $alert->id)->whereNotNull('clinical_trial_change_id')->select('clinical_trial_change_id'))
            ->where('created_at', '>=', $digest->period_start)
            ->where('created_at', '<', $digest->period_end);

        // Studies, not change records: a study updated in two registries counts once.
        $total = AlertMatch::query()
            ->where('alert_id', $alert->id)
            ->whereIn('clinical_trial_change_id', (clone $changes)->select('id'))
            ->distinct()
            ->count('clinical_trial_id');

        if ($total === 0) {
            $digest->update(['status' => DigestStatus::Empty, 'generated_at' => now()]);

            return;
        }

        $candidates = $changes->with('trial:id,source,source_id,canonical_trial_id,nct_id,brief_title,overall_status,last_update_post_date')
            ->latest('id')
            ->limit(self::MAX_RANKED)
            ->get();

        $ranked = $this->ranker->rank($candidates, TrialSearchCriteria::fromArray($alert->criteria ?? []), $language);
        // One item per study: keep the best-ranked change among its registrations.
        $ranked = collect($ranked)
            ->unique(fn (RankedChange $item): string => (string) ($item->change->trial?->canonicalId() ?? 'change-'.$item->change->id))
            ->values()
            ->all();
        $shown = array_slice($ranked, 0, max(1, $this->maxItems));
        $batch = $this->summarizer->summarize(array_map(fn (RankedChange $item): ClinicalTrialChange => $item->change, $shown), $language);

        $items = [];

        foreach ($shown as $rank => $item) {
            $change = $item->change;
            $trial = $change->trial;
            $summary = $batch->results[$change->id];
            $nctId = $trial?->nct_id;

            $items[] = [
                'rank' => $rank + 1,
                'change_id' => $change->id,
                'nct_id' => $nctId,
                'source' => $trial?->source,
                'source_id' => $trial?->source_id,
                'registry' => $trial !== null ? $this->sources->name($trial->source) : null,
                'title' => $trial?->brief_title,
                'url' => $trial !== null ? $this->sources->recordUrl($trial->source, $trial->source_id) : null,
                'update_date' => $trial?->last_update_post_date?->toDateString(),
                'update_date_label' => $trial?->last_update_post_date !== null
                    ? $this->writer->date(['date' => $trial->last_update_post_date->toDateString()], $language)
                    : null,
                'status' => $trial?->overall_status !== null ? $this->writer->status($trial->overall_status, $language) : null,
                'summary' => $summary->text,
                'summary_source' => $summary->source,
                'facts' => $this->writer->facts($change, $language),
                'reasons' => $item->reasons,
                'score' => $item->score,
                'change_summary_id' => $summary->changeSummaryId,
            ];
        }

        $start = $this->writer->date(['date' => $digest->period_start->toDateString()], $language);
        $end = $this->writer->date(['date' => $digest->period_end->subDay()->toDateString()], $language);
        $shownCount = count($items);
        $truncated = $total > $shownCount;
        $usedAi = $batch->cacheHits + $batch->generated;

        $content = [
            'name' => $alert->name,
            'language' => $locale,
            'title' => trans('digest.title', ['name' => $alert->name], $locale),
            'subject' => trans('digest.subject', ['name' => $alert->name, 'period' => trans('digest.period', ['start' => $start, 'end' => $end], $locale)], $locale),
            'period' => [
                'start' => $digest->period_start->toDateString(),
                'end' => $digest->period_end->subDay()->toDateString(),
                'label' => trans('digest.period', ['start' => $start, 'end' => $end], $locale),
            ],
            'total_updates' => $total,
            'shown_updates' => $shownCount,
            'truncated' => $truncated,
            'intro' => $truncated
                ? trans('digest.intro_truncated', ['count' => $total, 'start' => $start, 'end' => $end, 'shown' => $shownCount], $locale)
                : trans('digest.intro', ['count' => $total, 'start' => $start, 'end' => $end], $locale),
            'items' => array_map(fn (array $item): array => array_diff_key($item, ['change_summary_id' => true, 'score' => true]), $items),
            'labels' => [
                'source_ai' => trans('digest.source_ai', [], $locale),
                'source_factual' => trans('digest.source_factual', [], $locale),
                'view_registry' => trans('digest.view_registry', [], $locale),
                'updated_on' => trans('digest.updated_on', ['date' => ':date'], $locale),
                'missing_fact' => trans('digest.missing_fact', [], $locale),
                'why_matches' => trans('digest.why_matches', [], $locale),
            ],
            'notice' => trans('digest.notice', [], $locale),
            'manage' => trans('digest.manage', [], $locale),
        ];

        $generation = match (true) {
            $batch->fallbacks === 0 => DigestGeneration::Ai,
            $usedAi === 0 => DigestGeneration::Fallback,
            default => DigestGeneration::Partial,
        };

        $metrics = [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cache_hits' => $batch->cacheHits,
            'generated' => $batch->generated,
            'fallbacks' => $batch->fallbacks,
            'input_tokens' => $batch->inputTokens,
            'output_tokens' => $batch->outputTokens,
        ];

        $this->db->transaction(function () use ($digest, $items, $content, $generation, $batch, $metrics, $total, $shownCount): void {
            $digest->items()->delete();

            foreach ($items as $item) {
                $digest->items()->create([
                    'clinical_trial_change_id' => $item['change_id'],
                    'change_summary_id' => $item['change_summary_id'],
                    'rank' => $item['rank'],
                    'score' => $item['score'],
                    'summary_source' => $item['summary_source'],
                ]);
            }

            $digest->update([
                'status' => DigestStatus::Generated,
                'generation' => $generation,
                'fallback_reason' => $batch->fallbackReason,
                'total_updates' => $total,
                'shown_updates' => $shownCount,
                'content' => $content,
                'metrics' => $metrics,
                'generated_at' => now(),
            ]);
        });

        Log::info('digests.generated', [
            'digest_id' => $digest->id,
            'generation' => $generation->value,
            'fallback_reason' => $batch->fallbackReason,
            ...$metrics,
        ]);
    }
}
