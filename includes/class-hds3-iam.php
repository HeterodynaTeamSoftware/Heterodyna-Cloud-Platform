<?php
if (!defined('ABSPATH')) exit;
/** Organization memberships. Independent from API tokens; deny by default. */
final class HDS3_IAM {
    const ROLES = ['owner', 'admin', 'operator', 'viewer'];
    private static function table() { return HDS3_DB::table('tenant_members'); }
    public static function install() {
        if (get_option('hds3_iam_schema') === '2') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_user (tenant_id,user_id),
            KEY user_id (user_id),
            KEY tenant_role (tenant_id,role)
        ) $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) update_option('hds3_iam_schema','2',false);
    }
    public static function allowed($tenant_id, $user_id, $action) {
        if (!is_string($tenant_id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tenant_id)) return false;
        if (!$user_id || !get_userdata((int)$user_id)) return false;
        $tenant = HDS3_Tenants::get($tenant_id);
        if (!$tenant || ($tenant['status'] ?? '') !== 'active') return false;
        if (user_can((int)$user_id, 'manage_options')) return true; // platform superadmin
        global $wpdb;
        $role = $wpdb->get_var($wpdb->prepare('SELECT role FROM '.self::table().' WHERE tenant_id=%s AND user_id=%d', $tenant_id, (int)$user_id));
        $matrix = [
            'read' => self::ROLES,
            'operate' => ['owner','admin','operator'],
            'manage_members' => ['owner','admin'],
            'manage_tenant' => ['owner'],
        ];
        return isset($matrix[$action]) && in_array($role, $matrix[$action], true);
    }
    /** Tenant-scoped member details for the customer portal. */
    public static function list_for_portal($tenant_id, $actor) {
        if (!self::allowed($tenant_id, $actor, 'read')) return [];
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT user_id,role FROM '.self::table().' WHERE tenant_id=%s ORDER BY id LIMIT 200', $tenant_id), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
    private static function is_last_owner($tenant_id, $uid) {
        global $wpdb;
        $table = self::table();
        $role = $wpdb->get_var($wpdb->prepare("SELECT role FROM $table WHERE tenant_id=%s AND user_id=%d",$tenant_id,$uid));
        if ($role !== 'owner') return false;
        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE tenant_id=%s AND role=%s",$tenant_id,'owner'));
        return $count <= 1;
    }
    public static function members(WP_REST_Request $r) {
        $tenant_id = (string)$r['tenant_id'];
        if (!self::allowed($tenant_id, get_current_user_id(), 'read')) return new WP_Error('hds3_forbidden','Brak uprawnień organizacji',['status'=>403]);
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT user_id,role,created_at FROM '.self::table().' WHERE tenant_id=%s ORDER BY id LIMIT 200', $tenant_id), ARRAY_A);
        return rest_ensure_response(['tenant_id'=>$tenant_id,'members'=>$rows ?: []]);
    }
    public static function assign(WP_REST_Request $r) {
        $tenant_id=(string)$r['tenant_id']; $actor=get_current_user_id();
        if (!self::allowed($tenant_id,$actor,'manage_members')) return new WP_Error('hds3_forbidden','Brak uprawnień',['status'=>403]);
        $uid=absint($r['user_id']); $role=(string)$r['role'];
        if (!$uid || !get_userdata($uid) || !in_array($role,self::ROLES,true)) return new WP_Error('hds3_invalid_member','Niepoprawny użytkownik lub rola',['status'=>400]);
        // Only owners or WP superadmins can assign/change owner role.
        if ($role==='owner' && self::is_last_owner($tenant_id,$uid)) return new WP_Error('hds3_last_owner','Nie można usunąć ostatniego właściciela',['status'=>409]);
        if ($role==='owner' && !self::allowed($tenant_id,$actor,'manage_tenant')) return new WP_Error('hds3_forbidden','Zmiana właściciela wymaga roli owner',['status'=>403]);
        global $wpdb; $table=self::table();
        $existing=$wpdb->get_var($wpdb->prepare("SELECT role FROM $table WHERE tenant_id=%s AND user_id=%d",$tenant_id,$uid));
        if ($existing==='owner' && $role!=='owner' && self::is_last_owner($tenant_id,$uid)) return new WP_Error('hds3_last_owner','Nie można odebrać roli ostatniemu właścicielowi',['status'=>409]);
        if ($existing==='owner' && !self::allowed($tenant_id,$actor,'manage_tenant')) return new WP_Error('hds3_forbidden','Nie można zmienić właściciela',['status'=>403]);
        $now=current_time('mysql',true);
        $ok=$wpdb->query($wpdb->prepare("INSERT INTO $table (tenant_id,user_id,role,created_at,updated_at) VALUES (%s,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE role=VALUES(role),updated_at=VALUES(updated_at)",$tenant_id,$uid,$role,$now,$now));
        if ($ok===false) return new WP_Error('hds3_storage','Zapis nie powiódł się',['status'=>500]);
        if ($ok > 0) HDS3_Audit::record($tenant_id,$actor,$uid,$existing===null?'member_added':'role_changed',$existing??'',$role);
        return rest_ensure_response(['success'=>true,'tenant_id'=>$tenant_id,'user_id'=>$uid,'role'=>$role]);
    }
    public static function revoke(WP_REST_Request $r) {
        $tenant_id=(string)$r['tenant_id']; $uid=absint($r['user_id']); $actor=get_current_user_id();
        if (!self::allowed($tenant_id,$actor,'manage_members')) return new WP_Error('hds3_forbidden','Brak uprawnień',['status'=>403]);
        global $wpdb; $table=self::table();
        $role=$wpdb->get_var($wpdb->prepare("SELECT role FROM $table WHERE tenant_id=%s AND user_id=%d",$tenant_id,$uid));
        if ($role==='owner' && self::is_last_owner($tenant_id,$uid)) return new WP_Error('hds3_last_owner','Nie można usunąć ostatniego właściciela',['status'=>409]);
        if ($role==='owner' && !self::allowed($tenant_id,$actor,'manage_tenant')) return new WP_Error('hds3_forbidden','Nie można usunąć właściciela',['status'=>403]);
        $deleted=$wpdb->delete($table,['tenant_id'=>$tenant_id,'user_id'=>$uid],['%s','%d']);
        if ($deleted===false) return new WP_Error('hds3_storage','Usunięcie nie powiodło się',['status'=>500]);
        if ($deleted > 0) HDS3_Audit::record($tenant_id,$actor,$uid,'member_removed',$role??'','');
        return rest_ensure_response(['success'=>true,'removed'=>(bool)$deleted]);
    }
    /** Permissions are enforced before callbacks, not only inside handlers. */
    public static function permission(WP_REST_Request $r, $action) {
        if (!is_user_logged_in()) return new WP_Error('hds3_login_required', 'Wymagane logowanie.', ['status'=>401]);
        $tenant_id = (string)$r->get_param('tenant_id');
        if (!self::allowed($tenant_id, get_current_user_id(), $action)) {
            return new WP_Error('hds3_forbidden', 'Brak uprawnień organizacji.', ['status'=>403]);
        }
        return true;
    }
    public static function routes() {
        $args=['tenant_id'=>['required'=>true,'type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,64}$']];
        register_rest_route('heterodyna/v3','/iam/members',[
            ['methods'=>'GET','callback'=>[__CLASS__,'members'],'permission_callback'=>function($request) { return self::permission($request, 'read'); },'args'=>$args],
            ['methods'=>'POST','callback'=>[__CLASS__,'assign'],'permission_callback'=>function($request) { return self::permission($request, 'manage_members'); },'args'=>array_merge($args,['user_id'=>['required'=>true,'type'=>'integer','minimum'=>1],'role'=>['required'=>true,'type'=>'string','enum'=>self::ROLES]])],
            ['methods'=>'DELETE','callback'=>[__CLASS__,'revoke'],'permission_callback'=>function($request) { return self::permission($request, 'manage_members'); },'args'=>array_merge($args,['user_id'=>['required'=>true,'type'=>'integer','minimum'=>1]])],
        ]);
    }
}
