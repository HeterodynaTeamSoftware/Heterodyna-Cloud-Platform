<?php

if (!defined('ABSPATH')) exit;



class HDS3_DB {

    private static $cols_cache = [];

    public static function prefix() {

        global $wpdb;

        return $wpdb->prefix;

    }



    public static function table($name) {

        return self::prefix() . 'hds3_' . $name;

    }



    /**

     * Cache kolumn tabeli — pomaga obsłużyć legacy schematy.

     */

    public static function cols(string $table): array {

        if (isset(self::$cols_cache[$table])) return self::$cols_cache[$table];

        global $wpdb;

        $cols = [];

        try {

            $rows = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);

            foreach ((array)$rows as $r) {

                $f = (string)($r['Field'] ?? '');

                if ($f !== '') $cols[] = $f;

            }

        } catch (Throwable $e) {

            $cols = [];

        }

        self::$cols_cache[$table] = $cols;

        return $cols;

    }



    public static function has_col(string $table, string $col): bool {

        $cols = self::cols($table);

        if (!$cols) return false;

        return in_array($col, $cols, true);

    }



    /**

     * W niektórych bazach legacy kolumna typu zdarzenia występuje jako `etype`.

     * Admin/Widgety nie mogą się na tym wywracać.

     */

    public static function events_type_col(): string {

        static $cached = null;

        if (is_string($cached) && $cached !== '') return $cached;

        global $wpdb;

        $t = self::table('events');

        $cached = 'type';

        try {

            $cols = $wpdb->get_results("SHOW COLUMNS FROM $t", ARRAY_A);

            if (is_array($cols)) {

                $has_type = false; $has_etype = false;

                foreach ($cols as $c) {

                    $n = strtolower((string)($c['Field'] ?? ''));

                    if ($n === 'type') $has_type = true;

                    if ($n === 'etype') $has_etype = true;

                }

                if (!$has_type && $has_etype) $cached = 'etype';

            }

        } catch (Throwable $e) {

            // ignore

        }

        return $cached;

    }



    public static function activate() {

        self::create_tables();

        HDS3_Settings::set('schema_version', HDS3_VERSION);

        HDS3_Settings::init_defaults();

    }



    public static function maybe_upgrade() {

        // Upewnij się, że tabele istnieją i że ustawienia domyślne są zawsze dostępne.

        // To naprawia sytuację, gdy ktoś robi upgrade przez nadpisanie plików (bez de/aktywacji wtyczki),

        // a przez to nie wykona się register_activation_hook().

        $sv = HDS3_Settings::get('schema_version', '');

        if ($sv !== HDS3_VERSION) {

            self::create_tables();

            self::migrate_events_schema();

            self::migrate_licenses_schema();

            self::apply_performance_indexes();

            HDS3_Settings::set('schema_version', HDS3_VERSION);

        }

        // Schemat eventów ma być jeden (kolumna: type). Migracja jest bezpieczna i idempotentna.

        self::migrate_events_schema();

        self::migrate_licenses_schema();

        self::apply_performance_indexes();

        // Defaults muszą działać także przy upgrade.

        HDS3_Settings::init_defaults();

    }



    /**

     * Migracja tabeli settings dla baz legacy (odporna na rozjazdy PK/AUTO_INCREMENT).

     *

     * Typowe problemy na legacy:

     * - PRIMARY KEY na skey (bez kolumny id)

     * - id istnieje, ale bez AUTO_INCREMENT

     * - PRIMARY KEY jest gdzie indziej niż id

     * - brak UNIQUE na skey

     *

     * Zasada: jeśli tabela NIE jest w 100% zgodna z docelowym schematem, wykonujemy

     * bezpieczny swap (copy-table): tworzymy tabelę tymczasową o docelowym schemacie,

     * kopiujemy dane (best effort), podmieniamy nazwy.

     */

    private static function migrate_settings_schema(): void {

        global $wpdb;

        $t = self::table('settings');

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));

        if ($exists !== $t) return;



        $cols = self::cols($t);

        if (!$cols) return;



        $cols_l = array_map('strtolower', $cols);



        // informacje o kolumnach (AUTO_INCREMENT)

        $col_info = [];

        try {

            $rows = $wpdb->get_results("SHOW COLUMNS FROM `$t`", ARRAY_A);

            foreach ((array)$rows as $r) {

                $name = strtolower((string)($r['Field'] ?? ''));

                if ($name) $col_info[$name] = $r;

            }

        } catch (Throwable $e) {

            $col_info = [];

        }



        // Primary key columns / unique skey

        $pk_cols = [];

        $unique_skey = false;

        try {

            $idx = $wpdb->get_results("SHOW INDEX FROM $t", ARRAY_A);

            foreach ((array)$idx as $i) {

                $k = strtoupper((string)($i['Key_name'] ?? ''));

                $c = strtolower((string)($i['Column_name'] ?? ''));

                if ($k === 'PRIMARY' && $c) $pk_cols[] = $c;

                if (!$unique_skey && strtolower((string)($i['Key_name'] ?? '')) === 'skey' && (int)($i['Non_unique'] ?? 1) === 0) {

                    $unique_skey = true;

                }

            }

        } catch (Throwable $e) {

            // ignore

        }

        $pk_cols = array_values(array_unique(array_filter($pk_cols)));



        $has_id = in_array('id', $cols_l, true);

        $has_skey = in_array('skey', $cols_l, true);

        $has_svalue = in_array('svalue', $cols_l, true);

        $has_updated = in_array('updated_at', $cols_l, true);

        $pk_is_id = (count($pk_cols) === 1 && $pk_cols[0] === 'id');

        $id_auto = false;

        if ($has_id && isset($col_info['id'])) {

            $extra = strtolower((string)($col_info['id']['Extra'] ?? ''));

            $id_auto = (strpos($extra, 'auto_increment') !== false);

        }



        // Jeżeli już jest poprawnie (id PK + AUTO_INCREMENT + UNIQUE skey + wymagane kolumny) – nic nie rób.

        $is_ok = ($has_id && $has_skey && $has_svalue && $has_updated && $pk_is_id && $id_auto && $unique_skey);

        if ($is_ok) return;



        // Zrób swap do nowej tabeli z poprawnym schematem.

        $tmp = $t . '_tmp_' . substr(md5((string)microtime(true)), 0, 6);



        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $tmp (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            skey VARCHAR(191) NOT NULL,

            svalue LONGTEXT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY skey (skey)

        ) $charset;";



        $ok = $wpdb->query($sql);

        if ($ok === false) return;



        // Skopiuj dane (best effort). Jeżeli legacy miało inne nazwy, mapujemy tylko to co istnieje.

        if ($has_skey) {

            $sel_svalue = $has_svalue ? 'svalue' : "''";

            $sel_updated = $has_updated ? 'updated_at' : "'" . esc_sql(hds3_now_gmt()) . "'";

            $wpdb->query("INSERT INTO $tmp (skey, svalue, updated_at) SELECT skey, $sel_svalue, $sel_updated FROM $t");

        } elseif (in_array('key', $cols_l, true)) {

            // ultra-legacy: `key` zamiast `skey`

            $sel_svalue = $has_svalue ? 'svalue' : (in_array('value', $cols_l, true) ? 'value' : "''");

            $sel_updated = $has_updated ? 'updated_at' : "'" . esc_sql(hds3_now_gmt()) . "'";

            $wpdb->query("INSERT INTO $tmp (skey, svalue, updated_at) SELECT `key`, $sel_svalue, $sel_updated FROM $t");

        }



        // Podmień tabele – w jednej transakcji nie zawsze zadziała, więc sekwencyjnie.

        $bak = $t . '_bak_' . substr(md5((string)microtime(true)), 0, 6);

        $wpdb->query("RENAME TABLE $t TO $bak, $tmp TO $t");



        // Spróbuj usunąć backup, ale nie wymuszaj (czasem brakuje uprawnień).

        $wpdb->query("DROP TABLE IF EXISTS $bak");

    }



    /**

     * Legacy: część baz ma UNIQUE na kolumnie `klucz` w tabeli licencji.

     * Jeśli jej nie uzupełnimy, drugi insert wywali się na Duplicate entry '' for key ...klucz.

     */

    private static function migrate_licenses_schema(): void {

        global $wpdb;

        $t = self::table('licenses');

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));

        if ($exists !== $t) return;



        $cols = self::cols($t);

        if (!$cols) return;



        $has_klucz = in_array('klucz', $cols, true);

        $has_license_key = in_array('license_key', $cols, true);

        if ($has_klucz && $has_license_key) {

            $wpdb->query("UPDATE $t SET klucz = license_key WHERE (klucz IS NULL OR klucz='') AND license_key<>''");

        }

    }



    /**

     * Standaryzacja schematu eventów: wymuszamy kolumnę `type`.

     * Starsze instalacje mogły mieć `etype`.

     */

    private static function migrate_events_schema() {

        global $wpdb;

        $t = self::table('events');



        // Jeśli tabeli nie ma (jeszcze), nie rób nic.

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t));

        if ($exists !== $t) return;



        $cols = $wpdb->get_results("SHOW COLUMNS FROM $t", ARRAY_A);

        if (!is_array($cols) || empty($cols)) return;

        $has_type = false;

        $has_etype = false;

        foreach ($cols as $c) {

            $name = strtolower((string)($c['Field'] ?? ''));

            if ($name === 'type') $has_type = true;

            if ($name === 'etype') $has_etype = true;

        }



        // Nic do zrobienia.

        if ($has_type) return;



        // Preferowany scenariusz: rename etype -> type.

        if ($has_etype) {

            $r = $wpdb->query("ALTER TABLE $t CHANGE etype type VARCHAR(64) NOT NULL");

            if ($r === false) {

                // Fallback: dodaj type i skopiuj.

                $wpdb->query("ALTER TABLE $t ADD COLUMN type VARCHAR(64) NOT NULL DEFAULT 'event' AFTER site");

                $wpdb->query("UPDATE $t SET type = etype WHERE type = 'event' OR type = ''");

            }

        } else {

            // Bardzo stare/niestandardowe: dodaj type.

            $wpdb->query("ALTER TABLE $t ADD COLUMN type VARCHAR(64) NOT NULL DEFAULT 'event' AFTER site");

        }



        // Indeks na type (best effort).

        $idx = $wpdb->get_results("SHOW INDEX FROM $t", ARRAY_A);

        $has_idx = false;

        foreach ((array)$idx as $i) {

            if (strtolower((string)($i['Key_name'] ?? '')) === 'type') { $has_idx = true; break; }

        }

        if (!$has_idx) {

            $wpdb->query("ALTER TABLE $t ADD KEY type (type)");

        }

    }





/**

 * Performance pack: dokładamy brakujące indeksy (best effort, idempotentne).

 */

private static function apply_performance_indexes() {

    // wykonaj tylko raz

    $done = HDS3_Settings::get('perf_indexes_v1', '0');

    if ($done === '1') return;



    $ops = [

        // events

        [self::table('events'), 'type_created_at', ['type','created_at']],

        [self::table('events'), 'site_created_at', ['site','created_at']],

        // jobs

        [self::table('jobs'), 'status_run_after', ['status','run_after']],

        // licenses

        [self::table('licenses'), 'tenant_status', ['tenant_id','status']],

        // instances

        [self::table('instances'), 'tenant_status', ['tenant_id','status']],

        // tokens

        [self::table('tokens'), 'tenant_status', ['tenant_id','status']],

        // billing

        [self::table('billing'), 'status_next_due', ['status','next_due_at']],

    ];



    foreach ($ops as $op) {

        $t = $op[0]; $name = $op[1]; $cols = $op[2];

        self::ensure_index($t, $name, $cols);

    }



    HDS3_Settings::set('perf_indexes_v1', '1');

}



private static function ensure_index($table, $name, $cols) {

    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));

    if ($exists !== $table) return;



    $idx = $wpdb->get_results("SHOW INDEX FROM $table", ARRAY_A);

    foreach ((array)$idx as $i) {

        if (strtolower((string)($i['Key_name'] ?? '')) === strtolower($name)) return;

    }



    $safe_cols = [];

    foreach ((array)$cols as $c) {

        $c = preg_replace('/[^a-z0-9_]/i', '', (string)$c);

        if ($c) $safe_cols[] = "`{$c}`";

    }

    if (empty($safe_cols)) return;



    $cols_sql = implode(',', $safe_cols);

    // best effort

    $wpdb->query("ALTER TABLE $table ADD KEY `{$name}` ({$cols_sql})");

}

    private static function create_tables() {

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // Legacy-safe: napraw schemat settings przed dbDelta (unikamy konfliktów AUTO_INCREMENT/PRIMARY KEY).

        self::migrate_settings_schema();



        $sql = [];



        $sql[] = "CREATE TABLE " . self::table('settings') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            skey VARCHAR(191) NOT NULL,

            svalue LONGTEXT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY skey (skey)

        ) $charset;";



        // EVENTS: standard to kolumna `type`.

        $sql[] = "CREATE TABLE " . self::table('events') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            site VARCHAR(191) NOT NULL,

            type VARCHAR(64) NOT NULL,

            level VARCHAR(16) NOT NULL DEFAULT 'info',

            message TEXT NULL,

            payload LONGTEXT NULL,

            created_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            KEY site (site),

            KEY type (type),

            KEY level (level),

            KEY created_at (created_at)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('licenses') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            license_key VARCHAR(128) NOT NULL,

            product VARCHAR(64) NOT NULL DEFAULT 'CORE',

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            meta LONGTEXT NULL,

            created_at DATETIME NOT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY license_key (license_key),

            KEY tenant_id (tenant_id),

            KEY product (product),

            KEY status (status)

        ) $charset;";



        // TENANTS (fundament SaaS). WordPress nie przechowuje tenantów w postach/opcjach.

        $sql[] = "CREATE TABLE " . self::table('tenants') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            name VARCHAR(191) NOT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            meta LONGTEXT NULL,

            created_at DATETIME NOT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY tenant_id (tenant_id),

            KEY status (status)

        ) $charset;";



        // PLANS + LIMITY (SaaS, odseparowane od WP)

        $sql[] = "CREATE TABLE " . self::table('plans') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            plan_key VARCHAR(64) NOT NULL,

            name VARCHAR(191) NOT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            limits LONGTEXT NULL,

            meta LONGTEXT NULL,

            created_at DATETIME NOT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY plan_key (plan_key),

            KEY status (status)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('tenant_plans') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            plan_key VARCHAR(64) NOT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY tenant_id (tenant_id),

            KEY plan_key (plan_key)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('instances') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            site VARCHAR(191) NOT NULL,

            product VARCHAR(64) NOT NULL DEFAULT 'CORE',

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            activated_at DATETIME NOT NULL,

            last_seen_at DATETIME NULL,

            meta LONGTEXT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY tenant_site_product (tenant_id, site, product),

            KEY site (site),

            KEY status (status)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('verification') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            subject_type VARCHAR(16) NOT NULL DEFAULT 'site',

            subject_id VARCHAR(191) NOT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'unverified',

            current_step VARCHAR(64) NOT NULL DEFAULT 'start',

            meta LONGTEXT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY subject (subject_type, subject_id),

            KEY status (status),

            KEY current_step (current_step)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('verification_steps') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            subject_type VARCHAR(16) NOT NULL DEFAULT 'site',

            subject_id VARCHAR(191) NOT NULL,

            step VARCHAR(64) NOT NULL,

            is_done TINYINT(1) NOT NULL DEFAULT 0,

            done_at DATETIME NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY subject_step (subject_type, subject_id, step),

            KEY is_done (is_done)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('jobs') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            jtype VARCHAR(64) NOT NULL,

            payload LONGTEXT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'queued',

            priority INT NOT NULL DEFAULT 10,

            attempts INT NOT NULL DEFAULT 0,

            max_attempts INT NOT NULL DEFAULT 3,

            run_after DATETIME NULL,

            created_at DATETIME NOT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            KEY status (status),

            KEY jtype (jtype),

            KEY run_after (run_after),

            KEY priority (priority)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('job_runs') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            job_id BIGINT UNSIGNED NOT NULL,

            started_at DATETIME NOT NULL,

            finished_at DATETIME NULL,

            result VARCHAR(16) NOT NULL DEFAULT 'ok',

            message TEXT NULL,

            PRIMARY KEY (id),

            KEY job_id (job_id),

            KEY started_at (started_at)

        ) $charset;";



        // TOKENY per TENANT (hashowane, scope, revoke/rotacja)

        $sql[] = "CREATE TABLE " . self::table('tokens') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            token_hash CHAR(64) NOT NULL,

            scopes LONGTEXT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            label VARCHAR(191) NULL,

            created_at DATETIME NOT NULL,

            last_used_at DATETIME NULL,

            revoked_at DATETIME NULL,

            PRIMARY KEY (id),

            UNIQUE KEY token_hash (token_hash),

            KEY tenant_id (tenant_id),

            KEY status (status),

            KEY last_used_at (last_used_at)

        ) $charset;";



        // AGENCY: subklucze i subtokenu (tunel do danych klienta + licencjonowanie)

        $sql[] = "CREATE TABLE " . self::table('agency_subkeys') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            parent_license_key VARCHAR(128) NOT NULL,

            subkey VARCHAR(128) NOT NULL,

            product VARCHAR(64) NOT NULL DEFAULT 'SEO',

            domain VARCHAR(191) NOT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            created_at DATETIME NOT NULL,

            expires_at DATETIME NULL,

            meta LONGTEXT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY subkey (subkey),

            KEY parent_license_key (parent_license_key),

            KEY domain (domain),

            KEY status (status)

        ) $charset;";



        $sql[] = "CREATE TABLE " . self::table('agency_subtokens') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            parent_license_key VARCHAR(128) NOT NULL,

            token_hash CHAR(64) NOT NULL,

            product VARCHAR(64) NOT NULL DEFAULT 'SEO',

            domain VARCHAR(191) NOT NULL,

            scopes LONGTEXT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            created_at DATETIME NOT NULL,

            last_used_at DATETIME NULL,

            expires_at DATETIME NULL,

            PRIMARY KEY (id),

            UNIQUE KEY token_hash (token_hash),

            KEY parent_license_key (parent_license_key),

            KEY domain (domain),

            KEY status (status)

        ) $charset;";



        // AGENCY: rejestr konektorów (stan operacyjny + historia pod vault)

        $sql[] = "CREATE TABLE " . self::table('agency_connectors') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            parent_license_key VARCHAR(128) NOT NULL,

            domain VARCHAR(191) NOT NULL,

            product VARCHAR(64) NOT NULL DEFAULT 'SEO_CONNECTOR',

            plan_code VARCHAR(64) NULL,

            subkey VARCHAR(128) NULL,

            subtoken_hash CHAR(64) NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            created_at DATETIME NOT NULL,

            connected_at DATETIME NULL,

            disconnected_at DATETIME NULL,

            last_seen_at DATETIME NULL,

            last_error TEXT NULL,

            error_count INT NOT NULL DEFAULT 0,

            vault_path TEXT NULL,

            meta LONGTEXT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY parent_domain (parent_license_key, domain),

            KEY parent_license_key (parent_license_key),

            KEY domain (domain),

            KEY status (status),

            KEY last_seen_at (last_seen_at)

        ) $charset;";





        // BILLING READY (statusy rozliczeń per tenant, bez płatności - pod integrację Stripe/BLIK).

        $sql[] = "CREATE TABLE " . self::table('billing') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            tenant_id VARCHAR(64) NOT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            balance_cents BIGINT NOT NULL DEFAULT 0,

            currency VARCHAR(8) NOT NULL DEFAULT 'PLN',

            next_due_at DATETIME NULL,

            meta LONGTEXT NULL,

            updated_at DATETIME NOT NULL,

            PRIMARY KEY (id),

            UNIQUE KEY tenant_id (tenant_id),

            KEY status (status),

            KEY next_due_at (next_due_at)

        ) $charset;";



        // SEO: aktywacje kluczy (token per domena/instancja) + stan wtyczki/domeny + podstawy pod ops/auto-fix.

        $sql[] = "CREATE TABLE " . self::table('seo_activations') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            license_key VARCHAR(128) NOT NULL,

            token_hash CHAR(64) NOT NULL,

            token_full LONGTEXT NULL,

            domain VARCHAR(191) NOT NULL,

            owner VARCHAR(191) NULL,

            plan_code VARCHAR(64) NOT NULL,

            tier VARCHAR(16) NOT NULL DEFAULT 'basic',

            duration_months INT NOT NULL DEFAULT 1,

            domain_limit INT NOT NULL DEFAULT 1,

            activations_used INT NOT NULL DEFAULT 1,

            activations_limit INT NOT NULL DEFAULT 1,

            status VARCHAR(16) NOT NULL DEFAULT 'active',

            activated_at DATETIME NOT NULL,

            expires_at DATETIME NOT NULL,

            last_seen_at DATETIME NULL,

            plugin_version VARCHAR(32) NULL,

            wp_version VARCHAR(32) NULL,

            php_version VARCHAR(32) NULL,

            agency_enabled TINYINT(1) NOT NULL DEFAULT 0,

            connectors_total INT NOT NULL DEFAULT 0,

            connectors_ok INT NOT NULL DEFAULT 0,

            connectors_bad INT NOT NULL DEFAULT 0,

            last_error TEXT NULL,

            error_count INT NOT NULL DEFAULT 0,

            domain_http_code INT NULL,

            domain_checked_at DATETIME NULL,

            PRIMARY KEY (id),

            UNIQUE KEY token_hash (token_hash),

            UNIQUE KEY license_domain (license_key, domain),

            KEY license_key (license_key),

            KEY domain (domain),

            KEY status (status),

            KEY expires_at (expires_at),

            KEY last_seen_at (last_seen_at)

        ) $charset;";



        // SEO: kolejka komend dla klientów (pull-based). Wykorzystamy w kolejnych etapach (auto-fix/updates).

        $sql[] = "CREATE TABLE " . self::table('seo_commands') . " (

            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            token_hash CHAR(64) NOT NULL,

            command VARCHAR(64) NOT NULL,

            payload LONGTEXT NULL,

            status VARCHAR(16) NOT NULL DEFAULT 'queued',

            created_at DATETIME NOT NULL,

            done_at DATETIME NULL,

            result VARCHAR(16) NULL,

            message TEXT NULL,

            PRIMARY KEY (id),

            KEY token_hash (token_hash),

            KEY status (status),

            KEY created_at (created_at)

        ) $charset;";



        foreach ($sql as $q) {

            dbDelta($q);

        }

    }

}