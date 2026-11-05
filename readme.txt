=== Heterodyna Cloud Platform ===
Contributors: heterodyna
Tags: saas, multi-tenant, licensing, subscriptions, extensions
Requires at least: 6.0
Requires PHP: 8.0
Tested up to: 6.5
Stable tag: 03.00.182
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

== Description ==
Heterodyna Cloud Platform provides a WordPress-based SaaS administration foundation for organizations, access management, licenses, subscription records, metered usage, background tasks, alerts and extensions.

== Installation ==
1. Back up files and database. Test upgrade on staging.
2. Upload ZIP using Plugins > Add New > Upload Plugin, replacing the existing plugin.
3. Confirm the admin screens, database upgrades and REST permissions with separate tenant accounts.
4. Never enable outgoing mail/webhooks before reviewing configuration.

== Security and limitations ==
This is a development release, NOT verified production-ready. Offline syntax/static checks do not establish runtime security. Do not use for customer data until tenant isolation, REST authorization and database migrations are independently tested. See SECURITY.md and docs/DEVELOPMENT.md.

== Changelog ==
= 03.00.182 =
* Open-source release metadata, license text, contribution and security policy, CI syntax/static checks.
* Existing API, databases and functional modules remain unchanged.
