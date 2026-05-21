---
name: override-action
description: Override a specific CRUD action with a custom controller. Use when the user wants to customize an endpoint, replace the default index/store/show/update/destroy, or add custom logic to a route.
---

# Override Action

Replaces an auto-generated CRUD action with a custom controller implementation.

## Workflow

### Step 1: Identify the Override

Ask:
1. "Which model's action do you want to override?" (e.g., Post)
2. "Which action?" (index, store, show, update, destroy)
3. "What custom logic is needed?"

### Step 2: Exclude the Action on the Model

Add to the model:

```php
public static array $exceptActions = ['index']; // the action being overridden
```

This prevents the auto-generator from creating that route.

### Step 3: Create Custom Controller

Create `app/Http/Controllers/{ModelName}Controller.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\{ModelName};
use Illuminate\Http\Request;

class {ModelName}Controller extends Controller
{
    public function index(Request $request)
    {
        // Custom logic here
        // Remember: authorization is still handled by Policy
        // Organization scoping is still handled by GlobalController patterns

        return response()->json($results);
    }
}
```

### Step 4: Register Custom Route

Add to `routes/api.php` ABOVE the `require` line:

```php
// Custom override — registered first, takes priority
Route::get('{organization}/posts', [App\Http\Controllers\PostController::class, 'index'])
    ->middleware(['auth:sanctum', \Rhino\Http\Middleware\ResolveOrganizationFromRoute::class])
    ->name('posts.index');

// Auto-generated routes
require base_path('routes/global-routes.php');
```

Important:
- Keep the same route name (`{slug}.{action}`) for consistency
- Apply the same middleware the auto-generator would (auth:sanctum, org resolver)
- Multi-tenant prefix must match (`{organization}/` if using route prefix mode)

### Step 5: Verify

Run `php artisan route:list` and confirm your custom route appears instead of the auto-generated one.

## When to Use This

- The default CRUD action doesn't meet your needs (e.g., custom aggregation on index)
- You need to add complex business logic that doesn't fit in a scope or middleware
- You want a completely different response format for one action

## When NOT to Use This

- You just want to filter data differently — use a Scope instead
- You just want to add authorization — use a Policy instead
- You just want to add logging/headers — use Middleware instead
- You want to hide columns — use `hiddenColumns()` in the Policy instead
