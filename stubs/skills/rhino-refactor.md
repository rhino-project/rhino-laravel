# /rhino:refactor — Refactor to Rhino Patterns

You are refactoring existing code (possibly AI-generated) into proper Rhino conventions.

## Step 1: Analyze Current Code

- Read all controllers in app/Http/Controllers/
- Read all models in app/Models/
- Read routes/api.php
- Identify anti-patterns:
  - Business logic in controllers
  - Manual route definitions for CRUD
  - Missing policies
  - Missing validation on models
  - Inconsistent response formats
  - Raw SQL queries
  - No tests

## Step 2: Plan Refactor

For each resource found:
- List what will move to the Rhino model (validation, filters, sorts)
- List what will become a policy
- List what will become a scope
- Identify controllers that can be deleted (Rhino auto-generates them)

Present plan and wait for approval.

## Step 3: Refactor

For each resource:
1. Add Rhino traits to the model
2. Move validation from controller/FormRequest to model $validationRules
3. Move authorization to a Policy extending ResourcePolicy
4. Move query filtering to $allowedFilters, $allowedSorts, etc.
5. Create Scope if role-based data filtering exists
6. Register in config/rhino.php
7. Delete the controller (Rhino handles it)
8. Delete manual routes (Rhino handles it)

## Step 4: Write Tests

Write comprehensive tests for each refactored resource.

## Step 5: Verify

Run full test suite. Verify no regressions.

## Step 6: Commit
