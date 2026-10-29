# Contributor guide: rocksoftware-cakephp-db-sanitizer

A **CakePHP 5 plugin** (Composer package `makallio85/rocksoftware-cakephp-db-sanitizer`, CakePHP plugin `RockSoftware/DbSanitizer`) that masks copies of a production database. See `README.md`.

## Quick start

```bash
composer install
vendor/bin/phpunit
composer cs-check
composer stan
```

## Rules

1. **Pull requests target `dev`**, never `master`. Releases are tags on `dev` (`vX.Y.Z`) with a GitHub release.
2. **Never weaken a guard.** `mask` and `rehearse` refuse without `DB_SANITIZER_THROWAWAY=yes`. Nothing undeclared leaves `mask`: an undeclared table is emptied, an undeclared text column cleared, each with a schema drift warning. `check` and the coverage test fail on any gap. `verify` passes only a dump with a `mask` marker. A change that loosens any of these is refused.
3. **Deterministic or nothing.** A fake depends only on its kind, the original and the key. Never seed from time or randomness: a copy must stay consistent across tables and runs.
4. **No application knowledge.** The plugin knows no application's tables. Applications and their plugins declare theirs (`DeclarationsInterface`).
5. **Naming.** Yes/no names start with `is_`/`has_`/`can_`. One term per concept: a *declaration*, a *rule*, a *table plan*, an *outbound switch*, the *marker*.
6. **Tests** run on SQLite against a fictional schema (`tests/TestCase/SchemaTrait.php`). Test data is fictional (`*.example.test`, `example.org`, invented names).
7. **Public-ready repository.** No real company, application, person, host name, email address or phone number anywhere: code, tests, README, commits, issues, screenshots.
8. Do not commit `vendor/`, `composer.lock`, `tmp/` or `.phpunit.cache/`.
