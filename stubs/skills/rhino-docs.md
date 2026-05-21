# /rhino:docs — Generate API Documentation

You are generating API documentation for an Rhino project.

## Step 1: Read Config

Read config/rhino.php to get all registered models.

## Step 2: For Each Model

Read the model, policy, and scope. Generate documentation:

### Endpoints
- List all CRUD endpoints with URL pattern
- Show required/optional query parameters
- Show request body for store/update

### Fields
- List all fields with types
- Indicate which are required/optional
- Indicate which are hidden per role

### Filtering & Sorting
- List $allowedFilters with supported operators
- List $allowedSorts
- List $allowedIncludes
- List $allowedSearch fields

### Authorization
- Table of roles x actions (allowed or denied)
- Hidden columns per role
- Writable fields per role (store and update)

## Step 3: Generate Output

Create a markdown file at docs/API.md with all documentation.
If Postman is preferred: `php artisan rhino:export-postman`

## Step 4: Commit
