<?php
if (!defined('ABSPATH')) exit;
/** Tenant-scoped, read-only portal; no administrative privileges exposed. */
final class HDS3_Customer_Portal {
    /** Update only the tenant display name, never its ID, status or billing metadata. */
    public static function update_profile(): void {
        if (!is_user_logged_in()) wp_die('Wymagane logowanie.', '', ['response'=>401]);
        $tid = isset($_POST['tenant_id']) && is_string($_POST['tenant_id']) ? sanitize_text_field(wp_unslash($_POST['tenant_id'])) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tid)) wp_die('Nieprawidłowy identyfikator.', '', ['response'=>400]);
        check_admin_referer('hds3_portal_profile_'.$tid);
        if (!HDS3_IAM::allowed($tid, get_current_user_id(), 'manage_members')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        $tenant = HDS3_Tenants::get($tid);
        if (!$tenant || ($tenant['status'] ?? '') !== 'active') wp_die('Organizacja nieaktywna.', '', ['response'=>403]);
        $name = isset($_POST['organization_name']) && is_string($_POST['organization_name']) ? trim(sanitize_text_field(wp_unslash($_POST['organization_name']))) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) wp_die('Nazwa musi mieć od 1 do 120 znaków.', '', ['response'=>400]);
        HDS3_Tenants::update($tid, ['name'=>$name]);
        $ref = wp_get_referer();
        $base = $ref ? remove_query_arg(['hds_profile_status'], $ref) : home_url('/');
        wp_safe_redirect(add_query_arg(['hds_tenant'=>$tid,'hds_profile_status'=>'saved'], $base));
        exit;
    }
    public static function manage_member(): void {
        if (!is_user_logged_in()) wp_die('Wymagane logowanie.', '', ['response'=>401]);
        $tid = isset($_POST['tenant_id']) && is_string($_POST['tenant_id']) ? sanitize_text_field(wp_unslash($_POST['tenant_id'])) : '';
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tid) || !in_array($operation, ['assign','revoke'], true)) wp_die('Nieprawidłowe dane.', '', ['response'=>400]);
        check_admin_referer('hds3_portal_member_'.$tid);
        $actor = get_current_user_id();
        if (!HDS3_IAM::allowed($tid, $actor, 'manage_members')) wp_die('Brak uprawnień.', '', ['response'=>403]);
        $uid = 0;
        if ($operation === 'assign') {
            $email = isset($_POST['member_email']) && is_string($_POST['member_email']) ? sanitize_email(wp_unslash($_POST['member_email'])) : '';
            $user = is_email($email) ? get_user_by('email', $email) : false;
            if ($user) $uid = (int)$user->ID;
        } else {
            $uid = isset($_POST['member_user_id']) ? absint($_POST['member_user_id']) : 0;
        }
        $role = isset($_POST['member_role']) && is_string($_POST['member_role']) ? sanitize_key(wp_unslash($_POST['member_role'])) : '';
        if (!$uid || ($operation === 'assign' && !in_array($role, HDS3_IAM::ROLES, true))) {
            self::redirect_result($tid, 'invalid');
        }
        $req = new WP_REST_Request($operation === 'assign' ? 'POST' : 'DELETE', '/heterodyna/v3/iam/members');
        $req->set_param('tenant_id', $tid);
        $req->set_param('user_id', $uid);
        if ($operation === 'assign') $req->set_param('role', $role);
        $result = $operation === 'assign' ? HDS3_IAM::assign($req) : HDS3_IAM::revoke($req);
        self::redirect_result($tid, is_wp_error($result) ? $result->get_error_code() : 'saved');
    }
    private static function redirect_result($tid, $status): void {
        $referer = wp_get_referer();
        $base = $referer ? remove_query_arg(['hds_member_status'], $referer) : home_url('/');
        $url = add_query_arg(['hds_tenant'=>$tid, 'hds_member_status'=>$status], $base);
        wp_safe_redirect($url);
        exit;
    }
    public static function render($atts = []): string {
        if (!is_user_logged_in()) return '<section class="hds-cportal hds-cportal--notice"><h2>Portal organizacji</h2><p>Zaloguj się, aby zobaczyć swoje organizacje.</p>'.wp_login_form(['echo'=>false]).'</section>';
        if (!class_exists('HDS3_IAM') || !class_exists('HDS3_Tenants')) return '<p>Portal jest chwilowo niedostępny.</p>';
        global $wpdb;
        $uid = get_current_user_id();
        $members_table = HDS3_DB::table('tenant_members');
        $tenants_table = HDS3_DB::table('tenants');
        // Administrators see only their explicit memberships here too: this is the customer portal.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.tenant_id,m.role FROM $members_table m INNER JOIN $tenants_table t ON t.tenant_id=m.tenant_id WHERE m.user_id=%d AND t.status=%s ORDER BY m.tenant_id ASC LIMIT 100", $uid, 'active'
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $accessible = [];
        foreach ($rows as $row) {
            $tid = (string)$row['tenant_id'];
            if (HDS3_IAM::allowed($tid,$uid,'read')) $accessible[$tid] = (string)$row['role'];
        }
        $requested = isset($_GET['hds_tenant']) && is_string($_GET['hds_tenant']) ? sanitize_text_field(wp_unslash($_GET['hds_tenant'])) : '';
        if ($requested !== '' && !array_key_exists($requested,$accessible)) return '<section class="hds-cportal hds-cportal--notice"><h2>Brak dostępu</h2><p>Nie masz dostępu do wskazanej organizacji.</p></section>';
        $selected = $requested !== '' ? $requested : (string)(array_key_first($accessible) ?? '');
        wp_enqueue_style('hds3-customer-portal', HDS3_URL.'assets/customer-portal.css', [], HDS3_VERSION);
        ob_start(); ?>
        <section class="hds-cportal" aria-label="Panel organizacji">
          <div class="hds-cportal__heading"><div class="hds-cportal__brand"><span class="hds-cportal__dot" aria-hidden="true"></span> Heterodyna <strong>Cloud</strong></div><span class="hds-cportal__tag">Panel organizacji</span></div>
          <div class="hds-cportal__hero"><div><p class="hds-cportal__eyebrow">STREFA KLIENTA</p><h2>Twoja przestrzeń w chmurze</h2><p>Zarządzanie informacjami o organizacji i subskrypcji w jednym miejscu.</p></div><div class="hds-cportal__user"><?php echo esc_html(wp_get_current_user()->display_name); ?></div></div>
          <?php if (!$accessible): ?>
            <div class="hds-cportal__empty"><h3>Brak przypisanych organizacji</h3><p>Skontaktuj się z administratorem platformy, aby uzyskać członkostwo.</p></div>
          <?php else:
            $tenant = HDS3_Tenants::get($selected);
            $sub = class_exists('HDS3_Subscriptions') ? HDS3_Subscriptions::get($selected) : null;
            $sub_status = $sub ? HDS3_Subscriptions::effective_status($sub) : 'unregistered';
            $labels = ['active'=>'Aktywna','pending'=>'Oczekująca','past_due'=>'Zaległa','paused'=>'Wstrzymana','canceled'=>'Anulowana','expired'=>'Wygasła','unregistered'=>'Nieprzypisana'];
          ?>
          <nav class="hds-cportal__tenants" aria-label="Wybierz organizację">
            <?php foreach ($accessible as $id=>$role): $url=add_query_arg('hds_tenant',$id, get_permalink()); ?>
              <a <?php echo $id===$selected?'aria-current="page"':''; ?> class="hds-cportal__tenant <?php echo $id===$selected?'is-current':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($id); ?></a>
            <?php endforeach; ?>
          </nav>
          <div class="hds-cportal__grid">
            <article class="hds-cportal__card"><span>Organizacja</span><h3><?php echo esc_html((string)($tenant['name'] ?? $selected)); ?></h3><p>Identyfikator: <code><?php echo esc_html($selected); ?></code></p></article>
            <article class="hds-cportal__card"><span>Twoja rola</span><h3><?php echo esc_html(ucfirst($accessible[$selected])); ?></h3><p>Uprawnienia zależne od członkostwa.</p></article>
            <article class="hds-cportal__card"><span>Subskrypcja</span><h3><?php echo esc_html($labels[$sub_status] ?? $sub_status); ?></h3><p><?php echo $sub ? 'Plan: '.esc_html((string)$sub['plan_key']) : 'Brak zarejestrowanej subskrypcji'; ?></p></article>
          </div>
          <?php
            $can_manage = HDS3_IAM::allowed($selected, $uid, 'manage_members');
            $can_owner = HDS3_IAM::allowed($selected, $uid, 'manage_tenant');
            $members = HDS3_IAM::list_for_portal($selected, $uid);
            $notice = isset($_GET['hds_member_status']) && is_string($_GET['hds_member_status']) ? sanitize_key(wp_unslash($_GET['hds_member_status'])) : '';
          ?>
          <section class="hds-cportal__team" aria-label="Profil organizacji">
            <div class="hds-cportal__section-head"><div><p class="hds-cportal__eyebrow">USTAWIENIA</p><h3>Profil organizacji</h3><p>Nazwa widoczna dla członków zespołu. Identyfikator i stan organizacji pozostają niezmienne.</p></div></div>
            <?php if (isset($_GET['hds_profile_status']) && $_GET['hds_profile_status'] === 'saved'): ?><p role="status" class="hds-cportal__notice">Profil organizacji został zapisany.</p><?php endif; ?>
            <?php if ($can_manage): ?>
            <form class="hds-cportal__memberform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
              <input type="hidden" name="action" value="hds3_portal_profile" />
              <input type="hidden" name="tenant_id" value="<?php echo esc_attr($selected); ?>" />
              <?php wp_nonce_field('hds3_portal_profile_'.$selected); ?>
              <label>Nazwa organizacji<input name="organization_name" type="text" maxlength="120" required value="<?php echo esc_attr((string)($tenant['name'] ?? '')); ?>" /></label>
              <button type="submit" class="hds-cportal__button">Zapisz profil</button>
            </form>
            <?php else: ?><p class="hds-cportal__muted">Zmiany profilu może wprowadzić Owner lub Admin organizacji.</p><?php endif; ?>
          </section>
          <div class="hds-cportal__team" aria-label="Zespół organizacji">
            <div class="hds-cportal__section-head"><div><p class="hds-cportal__eyebrow">ORGANIZACJA</p><h3>Zespół i uprawnienia</h3><p>Zarządzaj dostępem do swojej organizacji. Konta użytkowników muszą już istnieć w WordPressie.</p></div><span><?php echo esc_html((string)count($members)); ?> członków</span></div>
            <?php if ($notice): ?><p role="status" class="hds-cportal__notice"><?php echo esc_html($notice === 'saved' ? 'Zmiany zostały zapisane.' : 'Operacja nie powiodła się: '. $notice); ?></p><?php endif; ?>
            <div class="hds-cportal__tablewrap"><table class="hds-cportal__table"><thead><tr><th>Użytkownik</th><th>Rola</th><?php if ($can_manage): ?><th>Akcje</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($members as $member): $muser = get_userdata((int)$member['user_id']); $mrole=(string)$member['role']; ?>
              <tr><td><strong><?php echo esc_html($muser ? $muser->display_name : 'Użytkownik #'.(int)$member['user_id']); ?></strong><small><?php echo esc_html($muser ? $muser->user_email : 'Konto niedostępne'); ?></small></td><td><span class="hds-cportal__role"><?php echo esc_html(ucfirst($mrole)); ?></span></td>
              <?php if ($can_manage): ?><td><?php if ($mrole !== 'owner' || $can_owner): ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Usunąć członkostwo tego użytkownika?');">
              <input type="hidden" name="action" value="hds3_portal_member"/><input type="hidden" name="operation" value="revoke"/><input type="hidden" name="tenant_id" value="<?php echo esc_attr($selected); ?>"/><input type="hidden" name="member_user_id" value="<?php echo esc_attr((string)$member['user_id']); ?>"/><?php wp_nonce_field('hds3_portal_member_'.$selected); ?><button type="submit" class="hds-cportal__button hds-cportal__button--secondary">Usuń</button></form>
              <?php else: ?><span class="hds-cportal__muted">Tylko właściciel</span><?php endif; ?></td><?php endif; ?></tr>
            <?php endforeach; ?></tbody></table></div>
            <?php if ($can_manage): ?><form class="hds-cportal__memberform" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="hds3_portal_member"/><input type="hidden" name="operation" value="assign"/><input type="hidden" name="tenant_id" value="<?php echo esc_attr($selected); ?>"/><?php wp_nonce_field('hds3_portal_member_'.$selected); ?>
              <label>Adres e-mail istniejącego użytkownika<input name="member_email" type="email" required autocomplete="off" placeholder="uzytkownik@example.com" /></label>
              <label>Rola<select name="member_role"><option value="viewer">Viewer — odczyt</option><option value="operator">Operator — operacje</option><option value="admin">Admin — członkowie</option><?php if ($can_owner): ?><option value="owner">Owner — właściciel</option><?php endif; ?></select></label>
              <button type="submit" class="hds-cportal__button">Dodaj lub zaktualizuj</button>
            </form><?php endif; ?>
          </div>
          <?php
            // Query only after membership checks above; never accept a tenant ID from a form/API here.
            $billing_rows = [];
            if (class_exists('HDS3_Billing_Periods')) {
                $billing_table = HDS3_DB::table('billing_periods');
                $billing_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT month_key,plan_key,subscription_status,usage_json,created_at FROM $billing_table WHERE tenant_id=%s ORDER BY month_key DESC LIMIT 12", $selected
                ), ARRAY_A);
                $billing_rows = is_array($billing_rows) ? $billing_rows : [];
            }
            $usage_month = gmdate('Y-m');
            $usage_report = null;
            if (class_exists('HDS3_Usage')) {
                $usage_start = $usage_month.'-01 00:00:00';
                $usage_end = (new DateTimeImmutable($usage_start, new DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d H:i:s');
                $usage_report = HDS3_Usage::report($selected, $usage_start, $usage_end);
            }
          ?>
          <section class="hds-cportal__team" aria-label="Zużycie usług">
            <div class="hds-cportal__section-head"><div><p class="hds-cportal__eyebrow">WYKORZYSTANIE</p><h3>Zużycie w <?php echo esc_html($usage_month); ?> (UTC)</h3><p>Wyłącznie zarejestrowane zdarzenia. Brak wpisów nie oznacza braku faktycznego zużycia. Limity są informacyjne.</p></div></div>
            <div class="hds-cportal__tablewrap"><table class="hds-cportal__table"><thead><tr><th>Metryka</th><th>Jednostki</th><th>Limit planu</th><th>Informacja</th></tr></thead><tbody>
              <?php if (!empty($usage_report['metrics'])): foreach ($usage_report['metrics'] as $metric=>$stat): ?>
              <tr><td><?php echo esc_html((string)$metric); ?></td><td><?php echo esc_html((string)$stat['used']); ?></td><td><?php echo esc_html($stat['limit']===null?'Nieokreślony':(string)$stat['limit']); ?></td><td><?php echo esc_html(!empty($stat['over_limit'])?'Ponad limitem':'—'); ?></td></tr>
              <?php endforeach; else: ?><tr><td colspan="4">Brak zarejestrowanych metryk w tym miesiącu.</td></tr><?php endif; ?>
            </tbody></table></div>
          </section>
          <section class="hds-cportal__team" aria-label="Historia okresów rozliczeniowych">
            <div class="hds-cportal__section-head"><div><p class="hds-cportal__eyebrow">HISTORIA</p><h3>Okresy rozliczeniowe</h3><p>Zapisane zestawienia zużycia, nie faktury ani potwierdzenia płatności. Maksymalnie 12 ostatnich miesięcy.</p></div></div>
            <div class="hds-cportal__tablewrap"><table class="hds-cportal__table"><thead><tr><th>Miesiąc UTC</th><th>Plan w chwili zapisu</th><th>Zarejestrowane jednostki</th><th>Data zapisu UTC</th></tr></thead><tbody>
              <?php if ($billing_rows): foreach ($billing_rows as $period):
                $snapshot=json_decode((string)$period['usage_json'], true);
                $metrics=is_array($snapshot) && isset($snapshot['metrics']) && is_array($snapshot['metrics']) ? $snapshot['metrics'] : [];
                $summary=[];
                foreach ($metrics as $metric=>$units) {
                    $summary[]=sanitize_key((string)$metric).': '.(is_numeric($units)?(string)(int)$units:'—');
                }
              ?>
              <tr><td><?php echo esc_html((string)$period['month_key']); ?></td><td><?php echo esc_html((string)$period['plan_key']); ?></td><td><?php echo esc_html($summary?implode(', ',$summary):'Brak zdarzeń w rejestrze'); ?></td><td><?php echo esc_html((string)$period['created_at']); ?></td></tr>
              <?php endforeach; else: ?><tr><td colspan="4">Nie utworzono jeszcze zestawień dla tej organizacji.</td></tr><?php endif; ?>
            </tbody></table></div>
          </section>
          <div class="hds-cportal__details"><div><h3>Informacje o subskrypcji</h3><p>Widok wyłącznie do odczytu. Zmiany planu obsługuje administrator platformy.</p></div><dl><dt>Stan</dt><dd><?php echo esc_html($labels[$sub_status] ?? $sub_status); ?></dd><dt>Koniec okresu (UTC)</dt><dd><?php echo esc_html(!empty($sub['period_end']) ? (string)$sub['period_end'] : 'Nie określono'); ?></dd></dl></div>
          <footer class="hds-cportal__footer">Heterodyna Team Software · Bezpieczny dostęp do informacji organizacji</footer>
          <?php endif; ?>
        </section><?php
        return (string)ob_get_clean();
    }
}
