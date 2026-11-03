<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use Database\Seeders\CaseStatusesSeeder;
use Database\Seeders\CurrenciesSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SptCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, CaseStatusesSeeder::class, CurrenciesSeeder::class]);
        $entity = Entity::create(['code' => 'AUTH', 'name' => 'Creation scope', 'entity_type' => 'AFFILIATE', 'tax_id' => 'AUTH']);
        $role = Role::where('code', 'STAFF')->firstOrFail();
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2025, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $period = Period::create(['fiscal_year_id' => $year->id, 'period_code' => '2025-03', 'year' => 2025, 'month' => 3, 'start_date' => '2025-03-01', 'end_date' => '2025-03-31']);
        $this->payload = ['entity_id' => $entity->id, 'fiscal_year_id' => $year->id, 'period_id' => $period->id,
            'case_type' => 'VAT', 'spt_position_type' => 'LB', 'spt_type' => 'Restitusi',
            'vat_in_amount' => '2000.00', 'vat_out_amount' => '1000.00', 'disputed_amount' => '1000.00'];
        $this->actingAs($this->user);
    }

    public static function filings(): array
    {
        return [
            'VAT Restitusi' => ['VAT', 'LB', 'Restitusi', '2000.00', '1000.00', '1000.00'],
            'VAT Kompensasi' => ['VAT', 'LB', 'Kompensasi', '2000.00', '1000.00', '1000.00'],
            'VAT preliminary' => ['VAT', 'LB', 'Pengembalian Pendahuluan', '2000.00', '1000.00', '1000.00'],
            'CIT Restitusi' => ['CIT', 'LB', 'Restitusi', null, null, '1000.00'],
            'VAT KB' => ['VAT', 'KB', null, '1000.00', '2000.00', '1000.00'],
            'VAT NIHIL' => ['VAT', 'NIHIL', null, '1000.00', '1000.00', '0.00'],
        ];
    }

    #[DataProvider('filings')]
    public function test_assigned_active_staff_can_create_without_starting_preliminary_processes(string $type, string $position, ?string $method, ?string $input, ?string $output, string $amount): void
    {
        $this->assertTrue($this->user->hasPermission('submit_cases'));
        $this->assertSame('AFFILIATE', $this->user->entity->entity_type);
        $payload = array_replace($this->payload, ['case_type' => $type, 'spt_position_type' => $position, 'spt_type' => $method,
            'vat_in_amount' => $input, 'vat_out_amount' => $output, 'disputed_amount' => $amount]);
        if ($type === 'CIT') {
            unset($payload['vat_in_amount'], $payload['vat_out_amount']);
        }
        $response = $this->postJson('/api/tax-cases', $payload)->assertCreated()
            ->assertJsonPath('data.spt_type', $method)->assertJsonPath('data.spt_position_type', $position)
            ->assertJsonPath('data.disputed_amount', $amount);
        $case = TaxCase::findOrFail($response->json('data.id'));
        $this->assertSame($this->user->entity_id, $case->entity_id);
        $this->assertSame($this->user->id, $case->user_id);
        $this->assertSame($method, $case->spt_type);
        $this->assertSame($position, $case->spt_position_type);
        $this->assertSame($amount, $case->disputed_amount);
        if ($type === 'VAT') {
            $this->assertSame($input, $case->vat_in_amount);
            $this->assertSame($output, $case->vat_out_amount);
        }
        $this->assertSame('DRAFT', $case->status->code);
        $this->assertDatabaseCount('preliminary_refund_requests', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        if ($method === 'Pengembalian Pendahuluan') {
            $this->postJson("/api/tax-cases/{$case->id}/preliminary-refund-request", ['requested_amount' => $amount])
                ->assertUnprocessable()->assertJsonValidationErrors('document');
            $this->assertDatabaseCount('preliminary_refund_requests', 0);
            $this->assertDatabaseCount('refund_processes', 0);
        }
    }

    public static function entityDenials(): array
    {
        return [['Restitusi'], ['Kompensasi'], ['Pengembalian Pendahuluan']];
    }

    #[DataProvider('entityDenials')]
    public function test_foreign_entity_is_a_real_403_for_every_lb_method(string $method): void
    {
        $foreign = Entity::create(['code' => 'OTHER', 'name' => 'Other entity', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTHER']);
        $this->postJson('/api/tax-cases', array_replace($this->payload, ['entity_id' => $foreign->id, 'spt_type' => $method]))
            ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_exception_trace_identifies_the_create_authorization_call(): void
    {
        $this->user->update(['entity_id' => null]);
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/tax-cases', $this->payload);
            $this->fail('An unassigned user must be denied.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
            $calls = array_filter($exception->getTrace(), fn (array $frame) => ($frame['function'] ?? '') === 'authorize'
                && ($frame['args'][0] ?? null) === 'create');
            $this->assertNotEmpty($calls);
            $this->assertStringContainsString('TaxCaseController.php', $exception->getTraceAsString());
        }
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_invalid_method_is_422_rather_than_unauthorized(): void
    {
        $this->postJson('/api/tax-cases', array_replace($this->payload, ['spt_position_type' => 'KB', 'vat_in_amount' => '1000.00', 'vat_out_amount' => '2000.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('spt_type');
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public static function capabilityDenials(): array
    {
        return ['inactive user' => ['user'], 'view only' => ['permission'], 'inactive role' => ['role'],
            'unassigned entity' => ['entity'], 'missing role' => ['missing-role'], 'seeded admin without submit' => ['admin']];
    }

    #[DataProvider('capabilityDenials')]
    public function test_creation_requires_an_active_user_and_creation_capability(string $denial): void
    {
        if ($denial === 'user') {
            $this->user->update(['is_active' => false]);
        } elseif ($denial === 'permission') {
            $this->user->role->update(['permissions' => ['view_cases']]);
        } elseif ($denial === 'entity') {
            $this->user->update(['entity_id' => null]);
        } elseif ($denial === 'missing-role') {
            $this->user->update(['role_id' => null]);
        } elseif ($denial === 'admin') {
            $this->user->update(['role_id' => Role::where('code', 'ADMIN')->firstOrFail()->id]);
        } else {
            $this->user->role->update(['is_active' => false]);
        }
        $this->postJson('/api/tax-cases', $this->payload + ['is_active' => true, 'permissions' => ['submit_cases'],
            'role_id' => Role::where('code', 'STAFF')->firstOrFail()->id, 'user_id' => $this->user->id])
            ->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_unknown_entity_is_a_validation_error_without_persistence(): void
    {
        $this->postJson('/api/tax-cases', array_replace($this->payload, ['entity_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('entity_id');
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_guests_receive_401_without_persistence(): void
    {
        auth()->forgetGuards();
        $this->postJson('/api/tax-cases', $this->payload)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_holding_keeps_existing_entity_boundary_but_still_needs_permission(): void
    {
        $holding = Entity::create(['code' => 'HOLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'HOLD']);
        $this->user->update(['entity_id' => $holding->id]);
        $this->user->role->update(['permissions' => ['view_cases']]);
        $this->postJson('/api/tax-cases', $this->payload)->assertForbidden();
        $this->assertDatabaseCount('tax_cases', 0);
        $this->user->role->update(['permissions' => ['submit_cases']]);
        $this->postJson('/api/tax-cases', $this->payload)->assertCreated();
        $this->assertDatabaseCount('tax_cases', 1);
    }
}
