<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

class Role extends SpatieRole
{
    use HasUuid;

    protected $fillable = ['name', 'guard_name', 'uuid', 'code', 'description', 'is_system', 'level'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Résolution `/api/rbac/roles/{role}` : UUID, id numérique, ou nom Spatie (`super_admin`, etc.).
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (Str::isUuid($value)) {
            $byUuid = static::query()->where('uuid', $value)->first();
            if ($byUuid) {
                return $byUuid;
            }
        }

        if (ctype_digit($value)) {
            $byId = static::query()->whereKey((int) $value)->first();
            if ($byId) {
                return $byId;
            }
        }

        return static::query()->where('name', $value)->first();
    }

    /**
     * Utilisateurs assignés au rôle (pivot model_has_roles).
     *
     * Surcharge Spatie : {@see getModelForGuard()} peut renvoyer null si `guard_name` est vide
     * ou si le guard n'a pas de provider (ex. `sanctum` absent de config/auth.php), ce qui provoque
     * « Class name must be a valid object or a string » lors de {@see withCount('users')}.
     */
    public function users(): BelongsToMany
    {
        $guard = $this->attributes['guard_name'] ?? null;
        if (! is_string($guard) || $guard === '') {
            $guard = (string) config('auth.defaults.guard');
        }

        $modelClass = Guard::getModelForGuard($guard) ?: User::class;

        return $this->morphedByMany(
            $modelClass,
            'model',
            config('permission.table_names.model_has_roles'),
            app(PermissionRegistrar::class)->pivotRole,
            config('permission.column_names.model_morph_key')
        );
    }

    public function permissionGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionGroup::class,
            'role_has_permission_groups',
            'role_id',
            'permission_group_id'
        );
    }
}
