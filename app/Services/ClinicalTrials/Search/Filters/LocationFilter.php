<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Models\ClinicalTrials\ClinicalTrialLocation;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Country, city and distance filters. All conditions must hold for the same study location.
 */
final class LocationFilter implements TrialFilter
{
    private const float EARTH_RADIUS_KM = 6371.0;

    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($criteria->country === null && $criteria->city === null && ! $criteria->hasDistance()) {
            return;
        }

        $query->whereHas('locations', function (Builder $location) use ($criteria): void {
            if ($criteria->country !== null) {
                $location->where('country', $criteria->country);
            }

            if ($criteria->city !== null) {
                $location->where('city', 'like', LikePattern::contains($criteria->city));
            }

            if ($criteria->hasDistance()) {
                $this->withinRadius($location, (float) $criteria->latitude, (float) $criteria->longitude, (float) $criteria->radiusKm);
            }
        });
    }

    /**
     * @param  Builder<ClinicalTrialLocation>  $location
     */
    private function withinRadius(Builder $location, float $latitude, float $longitude, float $radiusKm): void
    {
        // A bounding box first lets the database discard distant rows cheaply.
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $lonDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM / max(cos(deg2rad($latitude)), 0.01));

        $location
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$latitude - $latDelta, $latitude + $latDelta])
            ->whereBetween('longitude', [$longitude - $lonDelta, $longitude + $lonDelta])
            ->whereRaw(
                '(? * ACOS(LEAST(1, GREATEST(-1, COS(RADIANS(?)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(latitude)))))) <= ?',
                [self::EARTH_RADIUS_KM, $latitude, $longitude, $latitude, $radiusKm],
            );
    }
}
