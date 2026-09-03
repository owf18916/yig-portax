<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $staffRole;

    private Entity $holding;

    private Entity $affiliate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create([
            'code' => 'ADMIN_TEST',
            'name' => 'User Administrator',
            'permissions' => ['manage_users'],
            'is_active' => true,
        ]);
        $this->staffRole = Role::create([
            'code' => 'STAFF_TEST',
            'name' => 'Staff Member',
            'permissions' => ['view_cases'],
            'is_active' => true,
        ]);
        $this->holding = $this->createEntity('HOLD', 'Holding Entity', 'HOLDING');
        $this->affiliate = $this->createEntity('AFF', 'Affiliate Entity', 'AFFILIATE', $this->holding->id);
    }

    public function test_unauthenticated_user_cannot_access_users_api(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_user_without_permission_cannot_access_user_management_endpoints(): void
    {
        $staff = $this->createUser(['role_id' => $this->staffRole->id]);

        $this->actingAs($staff)->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to manage users.');

        $this->actingAs($staff)->postJson('/api/users', [])->assertForbidden();
        $this->actingAs($staff)->getJson('/api/roles')->assertForbidden();
    }

    public function test_authorized_user_can_list_paginated_users_with_relations(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $this->createUser([
            'name' => 'Target Person',
            'role_id' => $this->staffRole->id,
            'entity_id' => $this->affiliate->id,
        ]);

        $this->actingAs($admin)->getJson('/api/users?per_page=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total']])
            ->assertJsonFragment(['name' => 'Staff Member'])
            ->assertJsonFragment(['name' => 'Affiliate Entity'])
            ->assertJsonMissing(['password']);
    }

    public function test_search_and_role_entity_status_filters_work_together(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $target = $this->createUser([
            'name' => 'Nadia Filterable',
            'email' => 'nadia@example.test',
            'department' => 'Tax Operations',
            'position' => 'Analyst',
            'role_id' => $this->staffRole->id,
            'entity_id' => $this->affiliate->id,
            'is_active' => false,
        ]);
        $this->createUser(['name' => 'Different User', 'role_id' => $this->staffRole->id]);

        $query = http_build_query([
            'search' => 'Tax Operations',
            'role_id' => $this->staffRole->id,
            'entity_id' => $this->affiliate->id,
            'status' => 'inactive',
        ]);

        $this->actingAs($admin)->getJson("/api/users?{$query}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $target->id);
    }

    public function test_authorized_user_can_create_user_with_hashed_password(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);

        $response = $this->actingAs($admin)->postJson('/api/users', $this->validPayload([
            'email' => 'created@example.test',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.email', 'created@example.test')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $created = User::where('email', 'created@example.test')->firstOrFail();
        $this->assertGreaterThan($admin->id, $created->id);
        $this->assertTrue(Hash::check('secret123', $created->password));
        $this->assertNotSame('secret123', $created->password);
    }

    public function test_create_rejects_invalid_role_invalid_entity_and_duplicate_email(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id, 'email' => 'existing@example.test']);

        $this->actingAs($admin)->postJson('/api/users', $this->validPayload([
            'email' => 'existing@example.test',
            'role_id' => 999999,
            'entity_id' => 999999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'role_id', 'entity_id']);
    }

    public function test_create_rejects_duplicate_email_as_validation_without_database_details(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $this->createUser(['email' => 'existing@example.test']);

        $this->actingAs($admin)->postJson('/api/users', $this->validPayload([
            'email' => 'existing@example.test',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonMissing(['SQLSTATE'])
            ->assertJsonMissing(['duplicate key']);
    }

    public function test_update_changes_profile_assignments_and_preserves_password_when_omitted(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $user = $this->createUser(['password' => Hash::make('original-password')]);
        $originalHash = $user->password;

        $payload = $this->validPayload([
            'name' => 'Updated Name',
            'email' => $user->email,
            'role_id' => $this->adminRole->id,
            'entity_id' => $this->affiliate->id,
        ]);
        unset($payload['password'], $payload['password_confirmation']);

        $this->actingAs($admin)->patchJson("/api/users/{$user->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.role.id', $this->adminRole->id)
            ->assertJsonPath('data.entity.id', $this->affiliate->id);

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_update_changes_password_only_when_provided(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $user = $this->createUser();

        $this->actingAs($admin)->patchJson("/api/users/{$user->id}", $this->validPayload([
            'email' => $user->email,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ]))->assertOk();

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }

    public function test_admin_can_deactivate_and_reactivate_another_user(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        $user = $this->createUser(['is_active' => true]);

        $this->actingAs($admin)->patchJson("/api/users/{$user->id}/status", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse($user->fresh()->is_active);

        $this->actingAs($admin)->patchJson("/api/users/{$user->id}/status", ['is_active' => true])
            ->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);

        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}/status", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_role_and_entity_assignment_options_are_authorized_and_active_only(): void
    {
        $admin = $this->createUser(['role_id' => $this->adminRole->id]);
        Role::create(['code' => 'OLD', 'name' => 'Old Role', 'permissions' => [], 'is_active' => false]);
        $inactiveEntity = $this->createEntity('OLD', 'Inactive Entity', 'AFFILIATE', $this->holding->id, false);

        $this->actingAs($admin)->getJson('/api/roles')
            ->assertOk()->assertJsonFragment(['name' => 'Staff Member'])->assertJsonMissing(['name' => 'Old Role']);
        $this->actingAs($admin)->getJson('/api/user-management/entities')
            ->assertOk()->assertJsonFragment(['name' => 'Affiliate Entity'])->assertJsonMissing(['id' => $inactiveEntity->id]);
    }

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $this->staffRole->id,
            'entity_id' => $this->holding->id,
            'is_active' => true,
        ], $attributes));
    }

    private function createEntity(string $code, string $name, string $type, ?int $parentId = null, bool $active = true): Entity
    {
        return Entity::create([
            'code' => $code,
            'name' => $name,
            'entity_type' => $type,
            'parent_entity_id' => $parentId,
            'tax_id' => "tax-{$code}",
            'is_active' => $active,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Managed User',
            'email' => 'managed@example.test',
            'role_id' => $this->staffRole->id,
            'entity_id' => $this->holding->id,
            'phone' => '+62 21 1234',
            'position' => 'Tax Analyst',
            'department' => 'Tax',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }
}
