<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Enums\Locale;
use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrialChange;

/**
 * Writes the recorded before-and-after values of a change as plain factual sentences.
 *
 * These lines are shown in every digest item, used as the summary when AI is unavailable,
 * and given to the model as its only facts. Dates keep their original precision: "2025-06"
 * is written as a month, never as a day.
 */
final class FactualChangeWriter
{
    /** Site changes listed one by one; the rest are counted. */
    public const int MAX_SITE_LINES = 5;

    /**
     * @return list<string>
     */
    public function facts(ClinicalTrialChange $change, Locale $language): array
    {
        $locale = $language->value;

        if ($change->type === TrialChangeType::Created) {
            return [$change->new_status !== null
                ? $this->t('fact.created', ['status' => $this->status($change->new_status, $language)], $locale)
                : $this->t('fact.created_no_status', [], $locale)];
        }

        $details = $change->details ?? [];

        if ($details === [] && $change->previous_status !== $change->new_status) {
            $details = [['field' => 'overall_status', 'before' => $change->previous_status, 'after' => $change->new_status]];
        }

        $lines = [];
        $siteLines = 0;
        $extraSites = 0;

        foreach ($details as $detail) {
            $field = $detail['field'] ?? null;

            if ($field === 'site') {
                if ($siteLines >= self::MAX_SITE_LINES) {
                    $extraSites++;

                    continue;
                }

                $siteLines++;
            }

            $line = match ($field) {
                'overall_status' => $this->t('fact.status', [
                    'before' => $this->status($detail['before'] ?? null, $language),
                    'after' => $this->status($detail['after'] ?? null, $language),
                ], $locale),
                'site' => $this->site($detail, $language),
                'date' => $this->t('fact.date', [
                    'name' => $this->t('dates.'.($detail['name'] ?? 'start_date'), [], $locale),
                    'before' => $this->date($detail['before'] ?? null, $language),
                    'after' => $this->date($detail['after'] ?? null, $language),
                ], $locale),
                'results' => $this->t('fact.results', [], $locale),
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($extraSites > 0) {
            $lines[] = $this->t('fact.more_sites', ['count' => $extraSites], $locale);
        }

        return $lines !== [] ? $lines : [$this->t('fact.updated', [], $locale)];
    }

    /**
     * The factual summary used when no AI summary is available.
     */
    public function summary(ClinicalTrialChange $change, Locale $language): string
    {
        return implode(' ', array_slice($this->facts($change, $language), 0, 3));
    }

    public function status(?string $status, Locale $language): string
    {
        $key = 'digest.status.'.($status ?? 'none');
        $label = trans($key, [], $language->value);

        return $label === $key ? ucfirst(strtolower(str_replace('_', ' ', (string) $status))) : $label;
    }

    /**
     * @param  array{date?: string, type?: string|null}|null  $date
     */
    public function date(?array $date, Locale $language): string
    {
        $locale = $language->value;
        $value = $date['date'] ?? null;

        if (! is_string($value) || $value === '') {
            return $this->t('not_set', [], $locale);
        }

        /** @var list<string> $months */
        $months = trans('digest.months', [], $locale);
        $parts = explode('-', $value);
        $text = match (count($parts)) {
            1 => $parts[0],
            2 => $this->t('date_month', ['month' => $months[(int) $parts[1] - 1] ?? $parts[1], 'year' => $parts[0]], $locale),
            default => $this->t('date_day', ['month' => $months[(int) $parts[1] - 1] ?? $parts[1], 'day' => (int) $parts[2], 'year' => $parts[0]], $locale),
        };

        $type = $date['type'] ?? null;

        if (is_string($type) && trans("digest.date_type.{$type}", [], $locale) !== "digest.date_type.{$type}") {
            $text .= ' ('.$this->t("date_type.{$type}", [], $locale).')';
        }

        return $text;
    }

    /**
     * @param  array{facility?: ?string, city?: ?string, state?: ?string, country?: ?string}  $site
     */
    public function place(array $site): string
    {
        $parts = array_filter([$site['facility'] ?? null, $site['city'] ?? null, $site['state'] ?? null, $site['country'] ?? null], fn (?string $part): bool => $part !== null && $part !== '');

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function site(array $detail, Locale $language): string
    {
        $locale = $language->value;
        $place = $this->place((array) ($detail['site'] ?? []));

        return match ($detail['change'] ?? null) {
            'added' => ($detail['after_status'] ?? null) !== null
                ? $this->t('fact.site_added', ['site' => $place, 'status' => $this->status($detail['after_status'], $language)], $locale)
                : $this->t('fact.site_added_no_status', ['site' => $place], $locale),
            'removed' => $this->t('fact.site_removed', ['site' => $place], $locale),
            default => $this->t('fact.site_updated', [
                'site' => $place,
                'before' => $this->status($detail['before_status'] ?? null, $language),
                'after' => $this->status($detail['after_status'] ?? null, $language),
            ], $locale),
        };
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function t(string $key, array $replace, string $locale): string
    {
        return (string) trans("digest.{$key}", $replace, $locale);
    }
}
