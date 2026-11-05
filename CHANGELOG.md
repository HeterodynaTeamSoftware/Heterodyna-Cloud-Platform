## 03.00.173 — Universal SaaS Cleanup

- Nawigacja do Centrum danych; brak resetu automatycznego.
- Brak wymuszonych domyślnych planów i produktów na nowych instalacjach.
- Poprawki UI Planów i Limitów.

## 03.00.171 — Release Candidate / offline readiness review
- Version synchronization, packaging hygiene and offline regression verification.
- Explicit known-risk and live-integration checklist.
- No database resets or automatic mail/webhooks.

## 03.00.169 — Open Source & CI/CD
- Release documentation, CI quality checks, license text, contribution and security guidance; no database schema changes.

## 03.00.167 — Database Performance
- Dwa indeksy tenantowe pod raporty zużycia i operacje IAM; schematy wersjonowane.
- Testy regresji indeksów i zgodności silnika.

## 03.00.166 — Premium UI Consolidation
- Versioned premium stylesheet, consistent app components, focus/accessibility and regression verification.

## 03.00.163 — Customer Portal 2.0
- Historia do 12 miesięcy zamkniętych zestawień i bieżący raport zarejestrowanego wykorzystania w portalu klienta.
- Odczyt ograniczony do organizacji użytkownika; bez nowych uprawnień zapisu lub fikcyjnych kwot.

## 03.00.160
- Manual, tenant-scoped billing-period snapshots of recorded usage; no payments or invoices.

## 03.00.158
- Migration of Subscriptions and Usage admin entrypoints to protected Extensions; regression checks.

## 03.00.153
- Read-only Monitoring & Diagnostics Center, task status and local events.

## 03.00.151
- Correct dashboard canvas/heading contrast and navigation focus.
- Read-only legacy module inventory bridge, without changing existing boot behavior.
- Release version synchronization.

## 03.00.150
- Internal automation job queue, bounded retries, cron, admin diagnostics.

## 03.00.149
- Administracyjny podgląd i potwierdzane ręcznie czyszczenie danych tabel Heterodyna.
- Brak automatycznego usuwania przy aktualizacji.

## 03.00.148
- Consolidated console/settings branding and decluttered default WordPress dashboard.
- Preserved legacy aliases and all existing data/REST endpoints.

## 03.00.147
- Extension Manager: persisted opt-out, dependency guard, admin UI.

## 03.00.146
- Usage ledger, duplicate-safe events, advisory plan reporting; no payment execution.

## 03.00.145
- Portal: team management i ochrona ostatniego właściciela.

## 03.00.144
- Tenant-scoped read-only customer portal shortcode and isolated responsive styles.
- Explicit membership checks; no arbitrary tenant selection.

# Heterodyna Cloud Platform 03.00.138 — Application Console

## Changes
- Full application-style console on Heterodyna admin pages, with a dedicated navigation and an explicit return link to WordPress.
- SaaS-style light workspace, navy navigation, restrained brand accents, responsive KPI cards and real module shortcuts.
- Restores operational content to the dashboard; previous cleanup accidentally left only a small informational card.
- Metrics are taken from the existing tenants, license, billing and job tables; no invented customer numbers.
- Environment indicators are explicitly configuration-derived, **not live reachability tests**.
- Styling and navigation are scoped to Heterodyna pages. Normal WordPress screens retain standard administration UI.
- No new database migrations and no deletion of telemetry, legacy API code or business data.
- REST access rules from earlier security releases remain unchanged.

## Install / rollback
1. Back up WordPress database and the current plugin directory.
2. Upgrade the plugin using the full ZIP on staging first; retain the plugin folder slug on manual replacement.
3. Check: dashboard, licenses, organizations, health, settings, subscriptions, and API integrations.
4. To leave the console click **Powrót do WordPress** in the top-right corner.
5. If needed restore the previous v03.00.137 plugin ZIP and database backup.

## Known limitations
- No running WordPress/browser integration testing was performed.
- Some older plugin subpages still use legacy internal HTML tables/forms inside the new shell.
- Existing technical plugin slug, option and API names remain intentionally unchanged.
- App shell requires administrator permission `manage_options` like the current plugin.

## 03.00.143
- Introduced Core/Extensions registry with dependency-ordered lifecycle; legacy modules unchanged.

## 03.00.154
- Alerts registry, manual failed-queue scan, acknowledgement and administrative history.

## 03.00.159
- IAM audit trail, tenant-scoped read API, role assignment/removal event logging.

03.00.163 — Source-based OpenAPI inventory and regression guard.

## 03.00.164
- HMAC-signed opt-in outbound webhook delivery with automation retries and diagnostics.

## 03.00.165 — Extension Manager 2.0
- Diagnostyka zależności i czytelniejsze statusy modułów.
