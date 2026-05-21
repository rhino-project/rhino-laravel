# /rhino:feature — Add a New Feature

You are adding a new feature to an Rhino project. You follow TDD (Test-Driven Development) and Rhino conventions strictly.

## Before You Start

1. Read `.claude/CLAUDE.md` to understand the project context
2. Read `config/rhino.php` to see existing models and configuration
3. Ask the user: "What feature do you want to add?" Wait for their answer.

## Step 1: Plan

Before writing any code:
- Identify all files that need to be created or modified
- Identify which Rhino features are involved (models, policies, scopes, validation, etc.)
- Present the plan to the user and wait for approval

## Step 2: Write Tests First (TDD)

Write tests BEFORE implementation:
- Feature tests for every HTTP endpoint (status codes, response format, authorization)
- Test happy paths AND error cases (403, 404, 422)
- Test role-based access for every permission level
- Test multi-tenant isolation if applicable
- Test validation rules (valid and invalid data)
- Test soft delete, restore, force-delete if applicable
- Test hidden columns per role if applicable

Run tests — they should ALL FAIL (red phase).

## Step 3: Implement

Create/modify files following Rhino conventions:
- Migration with proper field types and foreign keys
- Model with required traits (HasValidation, HidableColumns, SoftDeletes, HasFactory)
- Optional traits: BelongsToOrganization, HasAuditTrail, HasAutoScope, HasUuid
- Define $fillable, $validationRules, $validationRulesStore, $validationRulesUpdate
- Define static query properties: $allowedFilters, $allowedSorts, $defaultSort, $allowedIncludes, $allowedSearch
- Policy extending ResourcePolicy with hiddenColumns() and permittedAttributes*()
- Scope if role-based data filtering is needed
- Factory with realistic faker data
- Register model in config/rhino.php
- Seeder if needed

Run tests — they should ALL PASS (green phase).

## Step 4: Refactor

Review the implementation:
- Remove any duplication
- Ensure consistent patterns with existing models
- Check that no business logic leaked into controllers

Run tests again — still all green.

## Step 5: Update Documentation

- Update API documentation if it exists
- Add the new model/feature to any README or project docs
- If blueprint YAML exists, update it

## Step 6: Commit

Create a commit with a descriptive message following the project's git conventions (check .claude/CLAUDE.md for commit preferences).
