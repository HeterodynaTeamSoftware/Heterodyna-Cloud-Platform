# Security policy

This development release is **not production-certified**. Security checks shipped with the package are not a full penetration test.

Please report suspected vulnerabilities privately to **biuro@heterodynateamsoftware.pl**. Do not publish exploit code or sensitive evidence in public GitHub issues. Do not submit passwords, tokens or customer database exports.

High-priority review areas: REST permission callbacks; tenant object authorization; ownership checks; webhook SSRF/redirect behavior; notification secrets; WP-Cron/worker idempotency; migration and data reset controls.

The project currently has no guaranteed response SLA. Production deployments require independent testing, backups, monitoring and a documented incident response process.
