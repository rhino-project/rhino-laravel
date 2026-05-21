---
name: add-middleware
description: Create a middleware and assign it to a model's CRUD routes. Use when the user wants to add logging, rate limiting, headers, validation, or any request/response processing to a model.
---

# Add Middleware

Creates a middleware class and assigns it to a model's CRUD routes via model properties.

## Workflow

### Step 1: Gather Requirements

Ask:
1. "What should the middleware do?" (log requests, add headers, rate limit, validate, etc.)
2. "Which model should it apply to?"
3. "Should it apply to all CRUD actions, or specific ones?"
   - All actions: goes in `$middleware`
   - Specific actions: goes in `$middlewareActions` with action names (`index`, `store`, `show`, `update`, `destroy`)

### Step 2: Generate Middleware

Create `app/Http/Middleware/{MiddlewareName}.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class {MiddlewareName}
{
    public function handle(Request $request, Closure $next): Response
    {
        // Pre-processing (before controller)

        $response = $next($request);

        // Post-processing (after controller)

        return $response;
    }
}
```

### Step 3: Assign to Model

**For all actions:**

```php
public static array $middleware = [
    \App\Http\Middleware\{MiddlewareName}::class,
];
```

**For specific actions:**

```php
public static array $middlewareActions = [
    'store'   => [\App\Http\Middleware\{MiddlewareName}::class],
    'update'  => [\App\Http\Middleware\{MiddlewareName}::class],
];
```

Both can be used together — `$middleware` applies to the route group, `$middlewareActions` adds on top per individual route.

### Step 4: Verify

Run `php artisan route:list` to confirm the middleware appears on the routes.

## Strict Rules

- NEVER put authorization logic in middleware (use Policies)
- NEVER put data filtering in middleware (use Scopes)
- NEVER register middleware in controller constructors
- Keep each middleware focused on ONE concern
- Always call `$next($request)` unless intentionally aborting the request
