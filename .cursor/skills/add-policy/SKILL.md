---
name: add-policy
description: Create or update a policy for a model with role-based authorization and column hiding. Use when the user wants to add authorization, create a policy, hide columns by role, or set up permissions.
---

# Add Policy

Creates a policy with role-based CRUD authorization and column visibility.

## Workflow

### Step 1: Identify the Model

Read the model file to understand:
- All fields in `$fillable`
- Which fields might be sensitive (prices, internal notes, margins, etc.)
- Existing relationships

### Step 2: Ask About Permissions

Read `app/Models/Role.php` to find all roles, then ask:

1. "For each role, which CRUD actions are allowed?"

| Action | Admin | Editor | Viewer |
|--------|-------|--------|--------|
| List (viewAny) | ? | ? | ? |
| View (view) | ? | ? | ? |
| Create (create) | ? | ? | ? |
| Update (update) | ? | ? | ? |
| Delete (delete) | ? | ? | ? |

2. "Which columns should be hidden per role?"

| Column | Admin | Editor | Viewer | Guest |
|--------|-------|--------|--------|-------|
| cost_price | visible | hidden | hidden | hidden |
| margin | visible | hidden | hidden | hidden |

### Step 3: Generate the Policy

```php
<?php

namespace App\Policies;

use App\Models\{ModelName};
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Rhino\Policies\ResourcePolicy;

class {ModelName}Policy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) return ['sensitive_field_1', 'sensitive_field_2'];

        $org = request()->get('organization');

        if ($user->rolesInOrganization($org)->where('slug', 'admin')->exists()) {
            return [];
        }

        return ['sensitive_field_1'];
    }

    public function viewAny(User $user): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)->exists();
    }

    public function view(User $user, {ModelName} $model): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)->exists();
    }

    public function create(User $user): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)
            ->whereIn('slug', ['admin', 'editor'])->exists();
    }

    public function update(User $user, {ModelName} $model): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)
            ->whereIn('slug', ['admin', 'editor'])->exists();
    }

    public function delete(User $user, {ModelName} $model): bool
    {
        $org = request()->get('organization');
        return $user->rolesInOrganization($org)
            ->where('slug', 'admin')->exists();
    }
}
```

## Strict Rules

- ALWAYS use `$user->rolesInOrganization($org)` — never check ownership
- NEVER write `$user->id === $model->user_id` — that belongs in a Scope
- NEVER query model data for authorization decisions
- ALWAYS get organization from `request()->get('organization')`
- ALWAYS extend `ResourcePolicy`
