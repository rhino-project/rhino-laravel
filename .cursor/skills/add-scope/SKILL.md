---
name: add-scope
description: Create an Eloquent global scope for role-based data filtering. Use when the user wants to filter records by role, restrict data visibility, or scope queries based on the user.
---

# Add Scope

Creates an Eloquent global scope that filters records based on the user's role.

## Workflow

### Step 1: Identify the Model

Read the model file to understand:
- Fields that determine visibility (e.g., `is_published`, `status`, `author_id`, `department_id`)
- Existing relationships

### Step 2: Ask About Data Visibility Per Role

Read `app/Models/Role.php` to find all roles, then ask:

"For each role, which records of **{ModelName}** should they see?"

| Role | Visibility |
|------|-----------|
| Admin | All records |
| Editor | Published + own drafts |
| Viewer | Published only |

### Step 3: Generate the Scope

Create `app/Models/Scopes/{ModelName}Scope.php`:

```php
<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class {ModelName}Scope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        try {
            $user = auth('sanctum')->user();
        } catch (\Exception $e) {
            return;
        }

        if (!$user) return;

        $org = request()->get('organization');
        if (!$org) return;

        // Admin: sees all (org filtering handled by GlobalController)
        if ($user->rolesInOrganization($org)->where('slug', 'admin')->exists()) {
            return;
        }

        // Editor: published + own drafts
        if ($user->rolesInOrganization($org)->where('slug', 'editor')->exists()) {
            $builder->where(function ($q) use ($user) {
                $q->where('is_published', true)
                  ->orWhere('author_id', $user->id);
            });
            return;
        }

        // Default: published only
        $builder->where('is_published', true);
    }
}
```

### Step 4: Register on the Model

Add to the model's `booted()` method:

```php
protected static function booted()
{
    static::addGlobalScope(new \App\Models\Scopes\{ModelName}Scope());
}
```

### Step 5: Recursion Prevention

If the scope queries the User model (or any model that uses the same scope), add recursion prevention:

```php
private static bool $applying = false;

public function apply(Builder $builder, Model $model)
{
    if (self::$applying) return;

    self::$applying = true;
    try {
        // ... scope logic
    } finally {
        self::$applying = false;
    }
}
```

## Strict Rules

- Scopes ONLY filter data — they never authorize
- NEVER check if the user CAN do something (that's the Policy's job)
- ONLY determine WHICH records the user can see
- Handle unauthenticated gracefully (return early, don't throw)
- Use `request()->get('organization')` for org context
