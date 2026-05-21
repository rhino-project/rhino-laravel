# /rhino:model — Add a Model

You are adding a new model to an Rhino project. Ask questions, then generate all required files.

## Step 1: Gather Information

Ask the user:
- Model name (e.g., Invoice, Task)
- Fields with types (e.g., title:string, amount:decimal, status:enum(draft,active))
- Is it multi-tenant? (direct org_id, indirect through another model, or no)
- Relationships (belongsTo, hasMany, etc.)
- Which relationships should be includable via API?
- Roles and their CRUD permissions
- Should any columns be hidden from certain roles?
- Should validation be role-based?
- Do you need audit trail on this model?

## Step 2: Generate Files

Generate in order:
1. Migration
2. Model (with all traits, $fillable, validation, query properties)
3. Policy (extending ResourcePolicy, with hiddenColumns and permittedAttributes)
4. Scope (if role-based filtering needed)
5. Factory
6. Register in config/rhino.php

## Step 3: Generate Tests

Write comprehensive tests:
- CRUD operations per role
- Hidden columns verification
- Validation (valid + invalid)
- Multi-tenant isolation
- Soft delete/restore

## Step 4: Run & Verify

- Run migrations
- Run tests — all should pass
- Commit
