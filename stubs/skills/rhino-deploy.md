# /rhino:deploy — Pre-Deploy Checklist

You are preparing the project for deployment.

## Checklist

### Database
- [ ] All migrations are committed
- [ ] No pending migrations: `php artisan migrate:status`
- [ ] Seeders are up to date

### Security
- [ ] Run /rhino:audit — no CRITICAL or HIGH issues
- [ ] .env.example is up to date
- [ ] No secrets in source code
- [ ] CORS configured properly
- [ ] Rate limiting on auth endpoints

### Tests
- [ ] All tests pass: `php artisan test`
- [ ] Test coverage is acceptable

### Configuration
- [ ] config/rhino.php has all models registered
- [ ] Multi-tenant config matches production setup
- [ ] Cache config: `php artisan config:cache`
- [ ] Route cache: `php artisan route:cache`

### Environment
- [ ] APP_ENV=production
- [ ] APP_DEBUG=false
- [ ] Proper database credentials
- [ ] Queue driver configured (if using async jobs)
- [ ] Mail driver configured (if using invitations)

### Performance
- [ ] Database indexes on frequently filtered columns
- [ ] Eager loading configured ($allowedIncludes)
- [ ] Pagination enabled on all models

## Output

Present a deploy readiness report:
- READY: No issues found
- WARNING: Non-blocking issues that should be addressed
- BLOCKER: Must fix before deploying
