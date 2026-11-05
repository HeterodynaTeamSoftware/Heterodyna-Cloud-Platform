# Contributing

1. Open an issue describing the bug or feature (without secrets or private data).
2. Work on a separate branch and keep changes focused.
3. Preserve the existing database schema and public API unless a migration is documented.
4. Update the canonical `includes/` code and synchronize any same-named file under `engine_payload/hds3_core/includes/`.
5. Run `python3 tests/run-offline.py` and `python3 tools/check-package.py` with PHP CLI installed.
6. For security-critical changes, add tests for allowed and denied access across at least two tenants in a staging WordPress/MySQL environment.
7. Document migration, backup and rollback steps. Avoid committing credentials, data exports and generated runtime files.

Submitting a contribution does not guarantee it will be merged. All contributions must be legally redistributable under the project's license.
