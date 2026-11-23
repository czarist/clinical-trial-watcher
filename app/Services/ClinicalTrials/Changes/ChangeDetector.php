<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Changes;

/**
 * Lists the meaningful differences between two snapshots of a study as before-and-after
 * details. Pure: no database access.
 *
 * Detail shapes:
 * - {field: overall_status, before, after}
 * - {field: site, change: added|removed|updated, site: {facility, city, state, country}, before_status, after_status}
 * - {field: date, name: start_date|primary_completion_date|completion_date, before: {date, type}|null, after: {date, type}|null}
 * - {field: results, before: false, after: true} (results became available)
 */
final class ChangeDetector
{
    /**
     * @return list<array<string, mixed>>
     */
    public function diff(TrackedFacts $before, TrackedFacts $after): array
    {
        $details = [];

        if ($before->overallStatus !== $after->overallStatus) {
            $details[] = ['field' => 'overall_status', 'before' => $before->overallStatus, 'after' => $after->overallStatus];
        }

        foreach ($after->sites as $key => $site) {
            $previous = $before->sites[$key] ?? null;

            if ($previous === null) {
                $details[] = $this->site('added', $site, null, $site['status']);
            } elseif ($previous['status'] !== $site['status']) {
                $details[] = $this->site('updated', $site, $previous['status'], $site['status']);
            }
        }

        foreach (array_diff_key($before->sites, $after->sites) as $site) {
            $details[] = $this->site('removed', $site, $site['status'], null);
        }

        foreach (array_keys(TrackedFacts::DATES) as $name) {
            $old = $before->dates[$name] ?? null;
            $new = $after->dates[$name] ?? null;

            if ($old != $new) {
                $details[] = ['field' => 'date', 'name' => $name, 'before' => $old, 'after' => $new];
            }
        }

        if (! $before->hasResults && $after->hasResults) {
            $details[] = ['field' => 'results', 'before' => false, 'after' => true];
        }

        return $details;
    }

    /**
     * @param  array{facility: ?string, city: ?string, state: ?string, country: ?string, status: ?string}  $site
     * @return array<string, mixed>
     */
    private function site(string $change, array $site, ?string $beforeStatus, ?string $afterStatus): array
    {
        return [
            'field' => 'site',
            'change' => $change,
            'site' => [
                'facility' => $site['facility'],
                'city' => $site['city'],
                'state' => $site['state'],
                'country' => $site['country'],
            ],
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
        ];
    }
}
