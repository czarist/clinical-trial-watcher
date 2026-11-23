<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Enums\DigestLanguage;
use App\Enums\StudyStatus;
use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;

/**
 * Ranks the changes that matched a saved search, using only the saved-search criteria and the
 * recorded change details. The reasons are passed to the digest (and the model) as facts;
 * the model never decides relevance.
 *
 * Scores per detail: recruitment opened 50, recruiting site in a monitored country or city 45,
 * results posted 40, new study 30, other status change 25, status the search filters for 10,
 * other new site 15, site removed or key date changed 10, any other update 5. A change scores
 * the sum of its details; ties go to the most recent change.
 */
final readonly class DigestRanker
{
    public function __construct(private FactualChangeWriter $writer) {}

    /**
     * @param  iterable<ClinicalTrialChange>  $changes
     * @return list<RankedChange> most relevant first
     */
    public function rank(iterable $changes, TrialSearchCriteria $criteria, DigestLanguage $language): array
    {
        $matchLine = $this->matchLine($criteria, $language);
        $ranked = [];

        foreach ($changes as $change) {
            [$score, $reasons] = $this->score($change, $criteria, $language);

            if ($matchLine !== null) {
                $reasons[] = $matchLine;
            }

            $ranked[] = new RankedChange($change, $score, array_values(array_unique($reasons)));
        }

        usort($ranked, fn (RankedChange $a, RankedChange $b): int => [$b->score, $b->change->id] <=> [$a->score, $a->change->id]);

        return $ranked;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function score(ClinicalTrialChange $change, TrialSearchCriteria $criteria, DigestLanguage $language): array
    {
        $locale = $language->value;

        if ($change->type === TrialChangeType::Created) {
            return [30, [trans('digest.reason.new_study', [], $locale)]];
        }

        $details = $change->details ?? [];

        if ($details === [] && $change->previous_status !== $change->new_status) {
            $details = [['field' => 'overall_status', 'before' => $change->previous_status, 'after' => $change->new_status]];
        }

        $score = 0;
        $reasons = [];

        foreach ($details as $detail) {
            switch ($detail['field'] ?? null) {
                case 'overall_status':
                    $after = $detail['after'] ?? null;

                    if ($after === StudyStatus::Recruiting->value) {
                        $score += 50;
                        $reasons[] = trans('digest.reason.now_recruiting', [], $locale);
                    } else {
                        $score += 25;
                        $reasons[] = trans('digest.reason.status_changed', [], $locale);
                    }

                    if ($after !== null && in_array($after, $criteria->statuses, true)) {
                        $score += 10;
                        $reasons[] = trans('digest.reason.status_in_criteria', ['status' => $this->writer->status($after, $language)], $locale);
                    }

                    break;

                case 'site':
                    $site = (array) ($detail['site'] ?? []);
                    $recruiting = ($detail['after_status'] ?? null) === StudyStatus::Recruiting->value;

                    if (($detail['change'] ?? null) === 'removed') {
                        $score += 10;
                        $reasons[] = trans('digest.reason.site_removed', [], $locale);
                    } elseif ($recruiting && $this->inMonitoredRegion($site, $criteria)) {
                        $score += 45;
                        $reasons[] = trans('digest.reason.site_in_region', ['place' => $this->region($site, $criteria)], $locale);
                    } elseif (($detail['change'] ?? null) === 'added') {
                        $score += 15;
                        $reasons[] = trans('digest.reason.site_added', [], $locale);
                    }

                    break;

                case 'results':
                    $score += 40;
                    $reasons[] = trans('digest.reason.results_posted', [], $locale);

                    break;

                case 'date':
                    $score += 10;
                    $reasons[] = trans('digest.reason.date_changed', [], $locale);

                    break;
            }
        }

        if ($reasons === []) {
            return [5, [trans('digest.reason.updated', [], $locale)]];
        }

        return [min($score, 65535), $reasons];
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function inMonitoredRegion(array $site, TrialSearchCriteria $criteria): bool
    {
        if ($criteria->country === null && $criteria->city === null) {
            return false;
        }

        $same = static fn (?string $wanted, mixed $actual): bool => $wanted === null
            || (is_string($actual) && mb_strtolower(trim($actual)) === mb_strtolower(trim($wanted)));

        return $same($criteria->country, $site['country'] ?? null) && $same($criteria->city, $site['city'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function region(array $site, TrialSearchCriteria $criteria): string
    {
        return implode(', ', array_filter([
            $criteria->city !== null ? ($site['city'] ?? null) : null,
            $site['country'] ?? null,
        ]));
    }

    /**
     * "Matches this saved search: condition "asthma", country Brazil."
     */
    private function matchLine(TrialSearchCriteria $criteria, DigestLanguage $language): ?string
    {
        $locale = $language->value;
        $parts = [];
        $values = [
            'q' => $criteria->q,
            'condition' => $criteria->condition,
            'keyword' => $criteria->keyword,
            'mesh_term' => $criteria->meshTerm,
            'sponsor' => $criteria->sponsor,
            'country' => $criteria->country,
            'city' => $criteria->city,
            'status' => $criteria->statuses === [] ? null : implode(', ', array_map(fn (string $s): string => $this->writer->status($s, $language), $criteria->statuses)),
            'phase' => $criteria->phases === [] ? null : implode(', ', $criteria->phases),
        ];

        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = trans("digest.criteria.{$key}", ['value' => $value], $locale);
            }
        }

        return $parts === [] ? null : trans('digest.reason.matches', ['criteria' => implode(', ', $parts)], $locale);
    }
}
