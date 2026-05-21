# /rhino:plan — Plan a Feature

You are helping plan a feature before implementation. You explore the codebase, understand existing patterns, and propose an approach.

## Step 1: Understand

- Ask: "What feature do you want to build?"
- Read config/rhino.php to see existing models
- Read existing models, policies, and scopes to understand patterns
- Read .claude/CLAUDE.md for project context

## Step 2: Analyze

- Identify all files that need to be created or modified
- Identify which Rhino features are involved
- Check if similar patterns exist in the codebase
- Identify potential security considerations
- Identify test scenarios

## Step 3: Present Plan

Present a structured plan:

### New Files
- List each new file with its purpose

### Modified Files
- List each modified file with what changes

### Rhino Features Used
- Which traits, policies, scopes, etc.

### Database Changes
- New tables, columns, indexes, foreign keys

### Test Scenarios
- List all test cases that will be written

### Risks & Considerations
- Security implications
- Multi-tenant considerations
- Performance implications

Wait for user approval before any implementation.
