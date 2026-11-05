# Development and release checklist

## System boundaries

This code is a WordPress plugin with a local PHP runtime and a mirrored deployment payload. It is **not** a distributed standalone SaaS service. The existing OpenAPI inventory is not a complete audited public API contract.

## Pre-merge checks

- Run syntax and static checks in CI and locally.
- Validate authorization with two tenants (same endpoint, different resources): no cross-tenant reads/writes.
- Exercise IAM role elevation and removal of the final owner; confirm access denied where appropriate.
- Run database migration tests on an upgraded copy and a clean install.
- Test webhook destination validation, redirects and DNS rebinding mitigation in a controlled environment.
- Test failed email, cron, queue retries and duplicate delivery behavior.
- Review screens for WCAG contrast and correct labels.

## Release steps

1. Freeze dependencies and version metadata (`HDS3_VERSION`, plugin header, engine version, `readme.txt`).
2. Run all offline checks and WP-CLI smoke checks in staging.
3. Publish a migration and rollback note, plus hashes for packaged builds.
4. Never call a release production-ready based solely on static checks.
