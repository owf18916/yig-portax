<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'entity_id',
        'role_id',
        'phone',
        'position',
        'department',
        'last_login_at',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    // Relationships
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }

    public function workflowHistories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'changed_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole($roles): bool
    {
        if (is_string($roles)) {
            return $this->role && $this->role->name === $roles;
        }

        if (is_array($roles)) {
            return $this->role && in_array($this->role->name, $roles);
        }

        return false;
    }
}
