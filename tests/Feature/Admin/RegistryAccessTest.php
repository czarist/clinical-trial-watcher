<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ImportRunStatus;
use App\Enums\RoleName;
use App\Models\ImportRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class RegistryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sources(): array
    {
        $this->actingAsApiUser($this->userWithRole(RoleName::Admin));

        return collect($this->getJson('/api/admin/registries')->assertOk()->json('data'))->keyBy('key')->all();
    }

    public function test_operators_see_what_each_registry_needs(): void
    {
        config([
            'registries.options.mfds.service_key' => '',
            'registries.options.nci_cts.api_key' => 'a-secret-key',
            'registries.permissions.hra' => false,
        ]);

        $sources = $this->sources();

        $this->assertSame('active', $sources['clinicaltrials_gov']['status']);
        $this->assertSame('confirmation_advised', $sources['ctis']['status']);
        $this->assertSame('paid_use_unconfirmed', $sources['rebec']['status']);
        $this->assertFalse($sources['rebec']['commercial_use']);
        $this->assertSame('needs_key', $sources['mfds']['status']);
        $this->assertSame([['name' => 'REGISTRY_MFDS_SERVICE_KEY', 'is_set' => false]], $sources['mfds']['settings']);
        $this->assertSame('needs_permission', $sources['hra']['status']);
        $this->assertSame('https://www.hra.nhs.uk/', $sources['hra']['contact']);

        // A configured key is reported as set, never returned.
        $this->assertSame('active', $sources['nci_cts']['status']);
        $this->assertSame([['name' => 'REGISTRY_NCI_CTS_API_KEY', 'is_set' => true]], $sources['nci_cts']['settings']);
        $this->assertStringNotContainsString('a-secret-key', (string) json_encode($sources));
    }

    public function test_a_recorded_permission_turns_the_importer_on(): void
    {
        config(['registries.permissions.hra' => true]);

        $hra = $this->sources()['hra'];

        $this->assertSame('paid_use_unconfirmed', $hra['status']);
        $this->assertTrue($hra['automated_access_allowed']);
        $this->assertSame([['name' => 'REGISTRY_PERMISSION_HRA', 'is_set' => true]], $hra['settings']);
    }

    public function test_the_last_run_and_unavailable_registries_are_listed(): void
    {
        ImportRun::create(['source' => 'isrctn', 'status' => ImportRunStatus::Failed, 'started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
        ImportRun::create(['source' => 'isrctn', 'status' => ImportRunStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);
        $this->actingAsApiUser($this->userWithRole(RoleName::Admin));

        $this->getJson('/api/admin/registries')
            ->assertOk()
            ->assertJsonPath('drafts', 'docs/registry-access-requests.md')
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('unavailable.0.name', 'WHO ICTRP')
                ->where('unavailable.0.reason', 'robots')
                ->etc());

        $this->assertSame('succeeded', $this->sources()['isrctn']['last_run']['status']);
        $this->assertNull($this->sources()['tfda']['last_run']);
    }

    public function test_only_import_managers_see_the_guide(): void
    {
        $this->getJson('/api/admin/registries')->assertUnauthorized();

        $this->actingAsApiUser($this->userWithRole());
        $this->getJson('/api/admin/registries')->assertForbidden();
    }
}
