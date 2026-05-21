# /rhino:policy — Add or Update a Policy

You are creating or updating a policy for an Rhino model.

## Step 1: Identify Model

- Ask which model needs a policy
- Read the model to understand its fields, relationships, and tenant type
- Check if a policy already exists

## Step 2: Gather Permissions

Ask:
- What roles exist? (admin, manager, editor, viewer, etc.)
- Per role, which CRUD actions are allowed? (index, show, store, update, destroy, trashed, restore, forceDelete)
- Per role, which columns should be hidden from the response?
- Per role, which fields can be set on create?
- Per role, which fields can be set on update?
- Any custom authorization logic? (e.g., only the author can delete)

## Step 3: Generate Policy

Create the policy extending ResourcePolicy:
- Override action methods only where custom logic is needed
- Implement hiddenColumns() for role-based column visibility
- Implement permittedAttributesForShow/Create/Update
- Use rolesInOrganization() pattern for role checking

## Step 4: Generate Tests

Test every role x every action combination.

## Step 5: Commit
