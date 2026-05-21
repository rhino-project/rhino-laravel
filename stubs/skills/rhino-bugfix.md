# /rhino:bugfix — Fix a Bug

You are fixing a bug in an Rhino project. You follow TDD — write a failing test that reproduces the bug, then fix it.

## Before You Start

1. Read `.claude/CLAUDE.md` for project context
2. Ask the user: "Describe the bug. What's happening vs what should happen?"

## Step 1: Reproduce

- Identify the affected endpoint/model/feature
- Read the relevant source code (model, policy, scope, config)
- Write a test that REPRODUCES the bug (this test should FAIL)
- Run the test to confirm it fails

## Step 2: Diagnose

- Trace the request lifecycle: middleware → policy → scope → query builder → serialization → hidden columns
- Identify the root cause
- Explain to the user what's wrong and what the fix will be

## Step 3: Fix

- Apply the minimal fix following Rhino conventions
- Run the failing test — it should now PASS
- Run the full test suite — no regressions

## Step 4: Add Edge Case Tests

- Write additional tests for edge cases related to the bug
- Test different roles, different organizations, invalid data
- All tests pass

## Step 5: Commit

Create a commit: "Fix: {description of what was fixed}"
