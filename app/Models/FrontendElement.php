<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Permission;

class FrontendElement extends Model
{
    use HasUuid;

    protected $table = 'frontend_elements';

    protected $fillable = [
        'code', 'name', 'description', 'menu_id', 'route',
        'icon', 'order', 'is_page', 'is_active', 'display_in_sidebar',
    ];

    protected function casts(): array
    {
        return [
            'is_page' => 'boolean',
            'is_active' => 'boolean',
            'display_in_sidebar' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'frontend_element_has_permissions',
            'frontend_element_id',
            'permission_id'
        );
    }

    public function isAccessibleBy(User $user): bool
    {
        if ($this->permissions()->count() === 0) {
            return true;
        }

        $userPermissions = $user->getAllEffectivePermissionCodes();
        $elementPermissions = $this->permissions()->pluck('name')->toArray();

        return count(array_intersect($userPermissions, $elementPermissions)) > 0;
    }
}
