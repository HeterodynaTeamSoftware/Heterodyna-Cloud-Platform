<?php
if (!defined('ABSPATH')) exit;
/** Tenant-scoped audit trail. Never store credentials or request bodies. */
final class HDS3_Audit {
    private static function table() { return HDS3_DB::table('iam_audit'); }
    public static function install() {
        if (get_option('hds3_iam_audit_schema') === '1') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id VARCHAR(64) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            target_user_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            old_role VARCHAR(24) NOT NULL DEFAULT '',
            new_role VARCHAR(24) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY tenant_time (tenant_id,created_at),
            KEY target_user (target_user_id)
        ) $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === $table) update_option('hds3_iam_audit_schema','1',false);
    }
    public static function record($tenant,$actor,$target,$event,$old,$new) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',(string)$tenant)) return false;
        if (!in_array($event,['member_added','role_changed','member_removed'],true)) return false;
        global $wpdb;
        return $wpdb->insert(self::table(),[
            'tenant_id'=>$tenant,'actor_id'=>(int)$actor,'target_user_id'=>(int)$target,
            'event_type'=>$event,'old_role'=>(string)$old,'new_role'=>(string)$new,
            'created_at'=>current_time('mysql',true)
        ],['%s','%d','%d','%s','%s','%s','%s']) === 1;
    }
    public static function events(WP_REST_Request $request) {
        $tenant=(string)$request->get_param('tenant_id');
        if (!HDS3_IAM::allowed($tenant,get_current_user_id(),'manage_members')) {
            return new WP_Error('hds3_forbidden','Brak dostępu do audytu organizacji',['status'=>403]);
        }
        global $wpdb;
        $limit=min(100,max(1,(int)$request->get_param('limit')));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,tenant_id,actor_id,target_user_id,event_type,old_role,new_role,created_at FROM '.self::table().' WHERE tenant_id=%s ORDER BY id DESC LIMIT %d',$tenant,$limit),ARRAY_A);
        return rest_ensure_response(['tenant_id'=>$tenant,'events'=>is_array($rows)?$rows:[]]);
    }
    public static function routes() {
        register_rest_route('heterodyna/v3','/iam/audit',[
            'methods'=>'GET','callback'=>[__CLASS__,'events'],
            'permission_callback'=>function($r){ return HDS3_IAM::permission($r,'manage_members'); },
            'args'=>['tenant_id'=>['required'=>true,'type'=>'string','pattern'=>'^[A-Za-z0-9_-]{1,64}$'],
              'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50]],
        ]);
    }
}
