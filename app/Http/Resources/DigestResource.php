<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Digest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A digest edition. The list shows its summary; the report adds the content shared with the email.
 *
 * @mixin Digest
 */
final class DigestResource extends JsonResource
{
    public bool $withContent = false;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alert_id' => $this->alert_id,
            'team_id' => $this->team_id,
            'name' => $this->content['name'] ?? null,
            'frequency' => $this->frequency->value,
            'language' => $this->language->value,
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->subDay()->toDateString(),
            'total_updates' => $this->total_updates,
            'shown_updates' => $this->shown_updates,
            'generation' => $this->generation?->value,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'content' => $this->when($this->withContent, fn () => $this->content),
        ];
    }

    public function withContent(): self
    {
        $this->withContent = true;

        return $this;
    }
}
