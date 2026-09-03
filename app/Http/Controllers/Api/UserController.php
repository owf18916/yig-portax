<?php

namespace App\Http\Controllers\Api;

use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

class UserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $users = User::query()
            ->select([
                'id', 'name', 'email', 'role_id', 'entity_id', 'phone',
                'position', 'department', 'last_login_at', 'is_active',
                'created_at', 'updated_at',
            ])
            ->with([
                'role:id,code,name',
                'entity:id,code,name',
            ])
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $term = '%'.$search.'%';
                    $query->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('department', 'like', $term)
                        ->orWhere('position', 'like', $term);
                });
            })
            ->when($validated['role_id'] ?? null, fn (Builder $query, int $roleId) => $query->where('role_id', $roleId))
            ->when($validated['entity_id'] ?? null, fn (Builder $query, int $entityId) => $query->where('entity_id', $entityId))
            ->when(($validated['status'] ?? 'all') !== 'all', fn (Builder $query) => $query->where('is_active', $validated['status'] === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return $this->success($users);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        try {
            $user = User::create([
                ...$this->profileData($validated),
                'password' => Hash::make($validated['password']),
                'is_active' => true,
            ]);
        } catch (Throwable $exception) {
            Log::error('Unable to create user.', [
                'actor_id' => $request->user()?->id,
                'email' => $validated['email'],
                'exception' => $exception,
            ]);

            return $this->error(
                'Unable to create the user. Please try again or contact the administrator.',
                500
            );
        }

        return $this->success(
            $this->loadUser($user),
            'User created successfully.',
            201
        );
    }

    public function show(User $user): JsonResponse
    {
        return $this->success($this->loadUser($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate($this->rules($user));
        $data = $this->profileData($validated);

        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return $this->success(
            $this->loadUser($user),
            'User updated successfully.'
        );
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if (! $validated['is_active'] && $request->user()->is($user)) {
            return $this->error(
                'You cannot deactivate your own account.',
                422,
                ['is_active' => ['You cannot deactivate your own account.']]
            );
        }

        $user->update(['is_active' => $validated['is_active']]);

        return $this->success(
            $this->loadUser($user),
            $validated['is_active'] ? 'User activated successfully.' : 'User deactivated successfully.'
        );
    }

    public function entities(): JsonResponse
    {
        return $this->success(
            Entity::query()
                ->select('id', 'code', 'name')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'entity_id' => ['required', 'integer', Rule::exists('entities', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ];
    }

    private function profileData(array $validated): array
    {
        return collect($validated)->only([
            'name', 'email', 'role_id', 'entity_id', 'phone', 'position', 'department',
        ])->all();
    }

    private function loadUser(User $user): User
    {
        return $user->load([
            'role:id,code,name',
            'entity:id,code,name',
        ]);
    }
}
