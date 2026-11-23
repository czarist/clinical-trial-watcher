<?php

declare(strict_types=1);

namespace App\Services\Registries\Deduplication;

use App\Enums\TrialLinkStatus;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialIdentifier;
use App\Models\ClinicalTrials\ClinicalTrialLink;
use App\Models\User;
use App\Services\Registries\Identifiers\TrialIdentifier;
use App\Services\Registries\SourceRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds records that describe the same study and groups them, so a study registered in
 * several registries (or registered twice) is shown, matched and notified once.
 *
 * Records are linked only when they share a normalized registry identifier, never by title.
 * Each group shows one record (the one from the registry with the highest priority, then the
 * oldest); the others point to it through `canonical_trial_id` and stay searchable by their
 * own identifier.
 *
 * Safeguards:
 * - two records of the same registry that share an identifier are a conflict, not a merge;
 * - a group that would hold two records of one registry through automatic links is not
 *   merged and its automatic links become conflicts;
 * - links a person confirmed or rejected keep that decision on every later import;
 * - automatic links whose shared identifier disappeared are removed and the group is redone.
 */
final readonly class DuplicateLinker
{
    public function __construct(
        private ConnectionInterface $db,
        private SourceRegistry $sources,
    ) {}

    /**
     * Replaces the record's identifiers and updates its links and group. Call it inside the
     * transaction that saves the record.
     *
     * @param  list<TrialIdentifier>  $identifiers
     */
    public function index(ClinicalTrial $trial, array $identifiers): void
    {
        $current = $trial->identifiers()->get(['scheme', 'value', 'is_primary'])
            ->map(fn (ClinicalTrialIdentifier $row): string => $row->scheme->value.':'.$row->value.':'.(int) $row->is_primary)
            ->sort()->values()->all();
        $next = collect($identifiers)
            ->map(fn (TrialIdentifier $identifier): string => $identifier->key().':'.(int) $identifier->isPrimary)
            ->sort()->values()->all();

        if ($current !== $next) {
            $trial->identifiers()->delete();
            $trial->identifiers()->createMany(array_map(fn (TrialIdentifier $identifier): array => [
                'scheme' => $identifier->scheme,
                'value' => $identifier->value,
                'is_primary' => $identifier->isPrimary,
            ], $identifiers));
        }

        $this->reconcile($trial);
    }

    /**
     * Brings the record's links in line with the identifiers it shares, then regroups every
     * record whose group may have changed.
     */
    public function reconcile(ClinicalTrial $trial): void
    {
        $shared = $this->sharedIdentifiers($trial);
        $existing = ClinicalTrialLink::query()->involving($trial->id)->get()
            ->keyBy(fn (ClinicalTrialLink $link): int => $link->otherId($trial->id));
        $affected = [$trial->id];

        foreach ($shared as $otherId => $identifier) {
            $other = $identifier->trial;
            $base = $other->source === $trial->source ? TrialLinkStatus::Conflict : TrialLinkStatus::Auto;
            $link = $existing->get($otherId);

            if ($link === null) {
                [$low, $high] = $trial->id < $otherId ? [$trial->id, $otherId] : [$otherId, $trial->id];
                ClinicalTrialLink::query()->create([
                    'clinical_trial_id' => $low,
                    'linked_trial_id' => $high,
                    'scheme' => $identifier->scheme,
                    'value' => $identifier->value,
                    'status' => $base,
                ]);
            } elseif (! $link->status->isReviewed()) {
                $link->fill(['scheme' => $identifier->scheme, 'value' => $identifier->value, 'status' => $base])->save();
            }

            $affected[] = $otherId;
        }

        // Automatic links whose identifier is no longer shared; reviewed links stay as decided.
        foreach ($existing as $otherId => $link) {
            if (! isset($shared[$otherId]) && ! $link->status->isReviewed()) {
                $link->delete();
                $affected[] = $otherId;
            }
        }

        $this->regroup($affected);
    }

    /**
     * Records a person's decision on a link and regroups both records.
     */
    public function review(ClinicalTrialLink $link, TrialLinkStatus $status, User $reviewer): ClinicalTrialLink
    {
        return $this->db->transaction(function () use ($link, $status, $reviewer): ClinicalTrialLink {
            $link->fill(['status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()])->save();
            $this->regroup([$link->clinical_trial_id, $link->linked_trial_id]);

            return $link;
        });
    }

    /**
     * Recomputes the group of each given record, and of the records grouped with them before.
     *
     * @param  list<int>  $trialIds
     */
    public function regroup(array $trialIds): void
    {
        $seeds = collect($trialIds)->unique();
        $canonicalIds = ClinicalTrial::query()->whereIn('id', $seeds)->whereNotNull('canonical_trial_id')->pluck('canonical_trial_id');
        $seeds = $seeds->merge($canonicalIds)->unique();
        $seeds = $seeds->merge(ClinicalTrial::query()->whereIn('canonical_trial_id', $seeds)->pluck('id'))->unique()->values();

        // Conflicts between different registries only came from a group that held one registry
        // twice; give them another chance, applyGroup() marks them again if that still holds.
        ClinicalTrialLink::query()
            ->where('status', TrialLinkStatus::Conflict)
            ->where(fn (Builder $q) => $q->whereIn('clinical_trial_id', $seeds)->orWhereIn('linked_trial_id', $seeds))
            ->with(['trial:id,source', 'linkedTrial:id,source'])
            ->get()
            ->filter(fn (ClinicalTrialLink $link): bool => $link->trial?->source !== $link->linkedTrial?->source)
            ->each(fn (ClinicalTrialLink $link) => $link->update(['status' => TrialLinkStatus::Auto]));

        $visited = [];

        foreach ($seeds as $seed) {
            if (isset($visited[$seed])) {
                continue;
            }

            $component = $this->component($seed);

            foreach ($component as $id) {
                $visited[$id] = true;
            }

            $this->applyGroup($component);
        }
    }

    /**
     * Records reachable from a record through grouping links.
     *
     * @return list<int>
     */
    private function component(int $start): array
    {
        $members = [$start => true];
        $frontier = [$start];

        while ($frontier !== []) {
            $links = ClinicalTrialLink::query()
                ->whereIn('status', [TrialLinkStatus::Auto, TrialLinkStatus::Confirmed])
                ->where(fn (Builder $q) => $q->whereIn('clinical_trial_id', $frontier)->orWhereIn('linked_trial_id', $frontier))
                ->get(['clinical_trial_id', 'linked_trial_id']);
            $frontier = [];

            foreach ($links as $link) {
                foreach ([$link->clinical_trial_id, $link->linked_trial_id] as $id) {
                    if (! isset($members[$id])) {
                        $members[$id] = true;
                        $frontier[] = $id;
                    }
                }
            }
        }

        $ids = array_keys($members);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     */
    private function applyGroup(array $ids): void
    {
        /** @var Collection<int, ClinicalTrial> $trials */
        $trials = ClinicalTrial::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'source', 'canonical_trial_id']);
        $canonicalId = null;

        if ($trials->count() > 1) {
            $automatic = ClinicalTrialLink::query()
                ->whereIn('clinical_trial_id', $ids)->whereIn('linked_trial_id', $ids)
                ->where('status', TrialLinkStatus::Auto);
            $sameRegistryTwice = $trials->countBy('source')->max() > 1;

            if ($sameRegistryTwice && (clone $automatic)->exists()) {
                // Automatic links would merge two records of one registry: let a person decide.
                $automatic->update(['status' => TrialLinkStatus::Conflict]);
            } else {
                $canonicalId = $trials
                    ->sortBy([
                        fn (ClinicalTrial $a, ClinicalTrial $b): int => $this->sources->priority($a->source) <=> $this->sources->priority($b->source),
                        fn (ClinicalTrial $a, ClinicalTrial $b): int => $a->id <=> $b->id,
                    ])
                    ->first()?->id;
            }
        }

        foreach ($trials as $trial) {
            $target = $canonicalId === null || $trial->id === $canonicalId ? null : $canonicalId;

            if ($trial->canonical_trial_id !== $target) {
                ClinicalTrial::query()->whereKey($trial->id)->update(['canonical_trial_id' => $target]);
            }
        }
    }

    /**
     * Other records carrying one of this record's identifiers, keyed by record id, with the
     * identifier they share (a primary identifier of either record is preferred as the reason).
     *
     * @return array<int, ClinicalTrialIdentifier>
     */
    private function sharedIdentifiers(ClinicalTrial $trial): array
    {
        $own = $trial->identifiers()->get(['scheme', 'value', 'is_primary']);

        if ($own->isEmpty()) {
            return [];
        }

        $primaryKeys = $own->where('is_primary', true)->map(fn (ClinicalTrialIdentifier $row): string => $row->scheme->value.':'.$row->value)->all();

        $matches = ClinicalTrialIdentifier::query()
            ->where('clinical_trial_id', '!=', $trial->id)
            ->where(function (Builder $query) use ($own): void {
                foreach ($own as $row) {
                    $query->orWhere(fn (Builder $q) => $q->where('scheme', $row->scheme)->where('value', $row->value));
                }
            })
            ->with('trial:id,source')
            ->get();

        return $matches
            ->sortByDesc(fn (ClinicalTrialIdentifier $row): int => (int) ($row->is_primary || in_array($row->scheme->value.':'.$row->value, $primaryKeys, true)))
            ->unique('clinical_trial_id')
            ->keyBy('clinical_trial_id')
            ->all();
    }
}
