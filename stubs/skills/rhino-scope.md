# /rhino:scope — Add a Custom Scope

You are adding a custom query scope to an Rhino model.

## Step 1: Understand

- Ask which model needs a scope
- Ask what data filtering is needed (e.g., "viewers should only see published posts", "managers see only their department's data")

## Step 2: Generate Scope

Create scope in app/Models/Scopes/{ModelName}Scope.php:
- Implement Illuminate\Database\Eloquent\Scope
- Use auth('sanctum')->user() and request()->get('organization')
- Use getRoleSlugForValidation() for role checking
- Apply query constraints based on role

Register in the model either via booted() or HasAutoScope trait.

## Step 3: Test

- Test that admin sees all records
- Test that restricted roles only see filtered records
- Test that unauthenticated users are handled
- Test multi-tenant: scope respects organization boundary

## Step 4: Commit
