<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Digest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Digest editions for operators: status, how they were written (AI, partial, fallback), the
 * fallback reason and the logged metrics. Shows no digest content.
 */
final class DigestRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Digest::query()->withCount(['deliveries as sent_count' => fn ($q) => $q->whereNotNull('sent_at')]);

        if ($request->filled('generation')) {
            $query->where('generation', (string) $request->query('generation'));
        }

        $page = $query->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Digest $digest): array => [
                'id' => $digest->id,
                'alert_id' => $digest->alert_id,
                'scope' => $digest->team_id !== null ? 'team' : 'personal',
                'frequency' => $digest->frequency->value,
                'language' => $digest->language->value,
                'period_start' => $digest->period_start->toDateString(),
                'period_end' => $digest->period_end->subDay()->toDateString(),
                'status' => $digest->status->value,
                'generation' => $digest->generation?->value,
                'fallback_reason' => $digest->fallback_reason,
                'total_updates' => $digest->total_updates,
                'shown_updates' => $digest->shown_updates,
                'sent_count' => $digest->getAttribute('sent_count'),
                'metrics' => $digest->metrics,
                'generated_at' => $digest->generated_at?->toIso8601String(),
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
