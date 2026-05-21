---
name: add-model
description: Create a new CRUD model with all required components (migration, model, policy, scope, config registration). Use when the user wants to add a new resource, create a new model, or set up a new CRUD endpoint.
---

# Add Model

Creates a complete CRUD resource with migration, model, policy, scope, config registration, factory, and seeder.

## Workflow

Follow this sequence strictly. Never skip steps.

### Step 1: Gather Requirements

Use the AskQuestion tool to ask:

1. "What is the model name?" (e.g., Product)
2. "What are the fields?" — For each field: name, type (string, integer, text, boolean, decimal, etc.), nullable?, unique?, default value?
3. "Does this model belong to an organization directly (has `organization_id`), or through a relationship chain?"
   - Direct: will use `BelongsToOrganization` trait
   - Indirect: Rhino auto-detects the ownership path by introspecting BelongsTo relationships.
4. "What relationships does this model have?" (belongsTo, hasMany, belongsToMany, etc.)

### Step 2: Ask About Permissions Per Role — MANDATORY

Never skip this step. Never assume permissions.

1. Read `app/Models/Role.php` to find existing role constants and the seeder to find all roles.
2. For EACH role, ask: "What can **{RoleName}** do with **{ModelName}**?"
   - Options: List (viewAny), View (view), Create (create), Update (update), Delete (delete)
3. "Which columns should be hidden from each role?"
   - Example: "Admin sees all columns. Viewer cannot see `cost_price`, `margin`."
4. "Which records should each role see?"
   - Options: All records, own department's records, own records only, published only, etc.
   - If all roles see all records, no scope is needed.

### Step 3: Generate Files

Create files in this order:

**1. Migration** — `database/migrations/{timestamp}_create_{table}_table.php`

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->foreignId('organization_id')->constrained()->onDelete('cascade'); // if direct
    $table->foreignId('category_id')->constrained()->onDelete('cascade'); // if indirect
    $table->string('name');
    $table->decimal('price', 10, 2);
    // ... fields from step 1
    $table->timestamps();
    $table->softDeletes();
});
```

**2. Model** — `app/Models/{ModelName}.php`

```php
<?php

namespace App\Models;

use Rhino\Models\RhinoModel;

class Product extends RhinoModel
{
    protected $fillable = ['name', 'price', 'category_id'];

    protected $validationRules = [
        'name' => 'required|string|max:255',
        'price' => 'required|numeric|min:0',
    ];

    protected $validationRulesStore = ['name', 'price'];
    protected $validationRulesUpdate = ['name', 'price'];

    public static $allowedFilters = ['name'];
    public static $allowedSorts = ['name', 'price', 'created_at'];
    public static $defaultSort = 'created_at';
    public static $allowedFields = ['id', 'name', 'price', 'created_at'];
    public static $allowedIncludes = ['category'];

    // Organization ownership is auto-detected from BelongsTo relationships.

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
```

> **Note:** `RhinoModel` includes `HasFactory`, `SoftDeletes`, `HasValidation`, `HidableColumns`, and `HasAutoScope` out of the box. For additional traits like `HasAuditTrail` or `BelongsToOrganization`, add them manually with `use`.

**3. Policy** — `app/Policies/{ModelName}Policy.php`

Based on step 2 answers. All checks use `rolesInOrganization()`:

```php
<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Rhino\Policies\ResourcePolicy;

class ProductPolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) return ['cost_price', 'margin'];
        $org = request()->get('organization');
        if ($user->rolesInOrganization($org)->where('slug', 'admin')->exists()) return [];
        return ['cost_price', 'margin'];
    }

    public function viewAny(User $user): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)->exists();
    }

    // ... implement all 5 CRUD methods based on step 2 answers
}
```

**4. Scope** — `app/Models/Scopes/{ModelName}Scope.php` (only if needed from step 2)

```php
<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProductScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // Role-based filtering from step 2 answers
    }
}
```

Register in the model's `booted()` method:

```php
protected static function booted()
{
    static::addGlobalScope(new \App\Models\Scopes\ProductScope());
}
```

**5. Config Registration** — Add to `config/rhino.php`:

```php
'models' => [
    // ... existing models
    'products' => \App\Models\Product::class,
],
```

**6. Factory and Seeder** — Create `database/factories/{ModelName}Factory.php` and optionally a seeder.

### Step 4: Verify Checklist

After generating, confirm:

- [ ] Model extends `RhinoModel` (which includes `HasValidation`, `HidableColumns`, `SoftDeletes`, `HasAutoScope`)
- [ ] Model has `$fillable`, `$validationRules`, `$validationRulesStore`, `$validationRulesUpdate`
- [ ] Model has query builder properties (`$allowedFilters`, `$allowedSorts`, etc.)
- [ ] Model ownership is auto-detected via BelongsTo relationships (or uses `BelongsToOrganization` trait)
- [ ] Policy extends `ResourcePolicy`
- [ ] Policy checks roles/permissions ONLY (no ownership checks)
- [ ] `hiddenColumns()` is implemented with role checks
- [ ] Scope (if needed) filters by roles only
- [ ] Model is registered in `config/rhino.php`
