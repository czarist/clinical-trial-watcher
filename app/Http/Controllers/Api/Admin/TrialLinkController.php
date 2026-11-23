<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TrialLinkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewTrialLinkRequest;
use App\Http\Resources\TrialLinkResource;
use App\Models\ClinicalTrials\ClinicalTrialLink;
use App\Models\User;
use App\Services\Registries\Deduplication\DuplicateLinker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Links between records that share a registry identifier. Operators confirm or reject them;
 * conflicts (records of one registry, or a group that would hold one registry twice) are not
 * grouped until someone decides.
 */
final class TrialLinkController extends Controller
{
    private const string TRIAL_COLUMNS = 'id,source,source_id,brief_title,overall_status,canonical_trial_id';

    public function __construct(private readonly DuplicateLinker $linker) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(TrialLinkStatus::class)],
        ]);
        $status = TrialLinkStatus::tryFrom((string) ($validated['status'] ?? '')) ?? TrialLinkStatus::Conflict;

        return TrialLinkResource::collection(
            ClinicalTrialLink::query()
                ->where('status', $status)
                ->with(['trial:'.self::TRIAL_COLUMNS, 'linkedTrial:'.self::TRIAL_COLUMNS, 'reviewer:id,name'])
                ->latest('id')
                ->paginate(50),
        );
    }

    public function update(ReviewTrialLinkRequest $request, ClinicalTrialLink $link): TrialLinkResource
    {
        /** @var User $user */
        $user = $request->user();
        $link = $this->linker->review($link, $request->decision(), $user);

        return new TrialLinkResource($link->load(['trial:'.self::TRIAL_COLUMNS, 'linkedTrial:'.self::TRIAL_COLUMNS, 'reviewer:id,name']));
    }
}
