# /rhino:migrate — Create a Migration

You are creating a database migration and updating the model to match.

## Step 1: Understand

- Ask: "What database change do you need?" (new table, add column, modify column, add index, etc.)
- Identify affected model(s)

## Step 2: Create Migration

Generate the migration file with proper:
- Column types and constraints
- Foreign keys with cascade rules
- Indexes for query performance
- Soft delete column if applicable

## Step 3: Update Model

Update the affected model:
- Add/remove from $fillable
- Update $validationRules
- Update $validationRulesStore/$validationRulesUpdate
- Update $allowedFilters, $allowedSorts, $allowedFields if new columns
- Update factory

## Step 4: Update Policy

If new columns have visibility restrictions:
- Update hiddenColumns()
- Update permittedAttributesForCreate/Update

## Step 5: Update Tests

- Add tests for new columns/features
- Run all tests — verify no regressions

## Step 6: Run Migration & Commit

Run:
```bash
php artisan migrate
```

Commit with message describing the schema change.
