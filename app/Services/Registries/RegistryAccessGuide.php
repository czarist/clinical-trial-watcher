<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Enums\RegistryAccessStatus;
use App\Models\ImportRun;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Tells operators, per registry, what is missing to use its data: an operator key,
 * the registry's written permission, or its confirmation of paid use; whom to contact;
 * and which settings to change. Setting values are never returned, only whether they
 * are set.
 */
final readonly class RegistryAccessGuide
{
    public const string DRAFTS = 'docs/registry-access-requests.md';

    public function __construct(
        private SourceRegistry $sources,
        private Config $config,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
        $lastRuns = ImportRun::query()
            ->whereIn('id', ImportRun::query()->selectRaw('max(id)')->groupBy('source'))
            ->get()
            ->keyBy('source');

        return array_map(function (string $key) use ($lastRuns): array {
            $source = $this->sources->get($key);
            $access = $source->access();
            $guidance = (array) $this->config->get("registries.guidance.{$key}", []);
            $settings = $this->settings($guidance);
            $run = $lastRuns->get($key);

            return [
                'key' => $key,
                'name' => $source->name(),
                'method' => $access->method->value,
                'status' => $this->status($guidance, $access)->value,
                'terms_url' => $access->termsUrl,
                'licence' => $access->licence,
                'attribution' => $access->attribution,
                'reviewed_on' => $access->reviewedOn,
                'automated_access_allowed' => $access->automatedAccessAllowed,
                'commercial_use' => $access->commercialUse,
                'contact' => $guidance['contact'] ?? null,
                'settings' => $settings,
                'last_run' => $run === null ? null : [
                    'status' => $run->status->value,
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'error' => $run->error,
                ],
            ];
        }, $this->sources->keys());
    }

    /**
     * @return list<array{name: string, reason: string, contact: ?string}>
     */
    public function unavailable(): array
    {
        return array_values(array_map(static fn (array $item): array => [
            'name' => (string) $item['name'],
            'reason' => (string) $item['reason'],
            'contact' => $item['contact'] ?? null,
        ], (array) $this->config->get('registries.unavailable', [])));
    }

    /**
     * @param  array<string, mixed>  $guidance
     */
    private function status(array $guidance, SourceAccess $access): RegistryAccessStatus
    {
        return match (true) {
            ! $access->automatedAccessAllowed => RegistryAccessStatus::NeedsPermission,
            isset($guidance['key']) && ! $this->filled($this->config->get($guidance['key']['config'])) => RegistryAccessStatus::NeedsKey,
            ! $access->commercialUse => RegistryAccessStatus::PaidUseUnconfirmed,
            (bool) ($guidance['confirm'] ?? false) => RegistryAccessStatus::ConfirmationAdvised,
            default => RegistryAccessStatus::Active,
        };
    }

    /**
     * Environment variables the operator sets for this registry, and whether each is set.
     *
     * @param  array<string, mixed>  $guidance
     * @return list<array{name: string, is_set: bool}>
     */
    private function settings(array $guidance): array
    {
        $settings = [];

        if (isset($guidance['key'])) {
            $settings[] = ['name' => (string) $guidance['key']['env'], 'is_set' => $this->filled($this->config->get($guidance['key']['config']))];
        }

        if (isset($guidance['permission'])) {
            $key = strtolower(substr((string) $guidance['permission'], strlen('REGISTRY_PERMISSION_')));
            $settings[] = ['name' => (string) $guidance['permission'], 'is_set' => (bool) $this->config->get("registries.permissions.{$key}", false)];
        }

        foreach ((array) ($guidance['settings'] ?? []) as $env => $configKey) {
            $settings[] = ['name' => (string) $env, 'is_set' => $this->filled($this->config->get((string) $configKey))];
        }

        return $settings;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== false;
    }
}
