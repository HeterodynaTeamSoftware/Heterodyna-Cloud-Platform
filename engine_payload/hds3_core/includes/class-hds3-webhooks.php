<?php
/** Opt-in outbound webhooks, HMAC signing and automation-backed delivery. */
if (!defined('ABSPATH')) exit;
final class HDS3_Webhooks {
 const SCHEMA='1';
 private static function table(){return HDS3_DB::table('webhook_deliveries');}
 public static function config(){
  $c=get_option('hds3_webhook_config',[]);if(!is_array($c))$c=[];
  return array_merge(['enabled'=>0,'url'=>'','event'=>'alert.open','secret'=>''],array_intersect_key($c,array_flip(['enabled','url','event','secret'])));
 }
 public static function install(){
  global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$t=self::table();$collate=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $t (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    event_id varchar(80) NOT NULL,
    event_type varchar(80) NOT NULL,
    status varchar(20) NOT NULL,
    http_code smallint unsigned NOT NULL DEFAULT 0,
    attempts tinyint unsigned NOT NULL DEFAULT 0,
    last_error text NULL,
    created_at datetime NOT NULL,
    updated_at datetime NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY event_id (event_id),
    KEY status_updated (status,updated_at)
  ) $collate;");
  if(!$wpdb->last_error)update_option('hds3_webhooks_schema',self::SCHEMA,false);
 }
 public static function maybe_install(){if(get_option('hds3_webhooks_schema')!==self::SCHEMA)self::install();}
 public static function init(){
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_post_hds3_webhooks_save',[__CLASS__,'save']);
  add_action('admin_post_hds3_webhooks_test',[__CLASS__,'test']);
  add_filter('hds3_automation_handlers',[__CLASS__,'register_handler']);
  add_action('hds3_webhook_alert_recorded',[__CLASS__,'on_alert'],10,2);
 }
 public static function register_handler($handlers){$handlers['webhook_delivery']=[__CLASS__,'deliver'];return $handlers;}
 public static function valid_url($url){
  if(!is_string($url)||strlen($url)>2048||!preg_match('~^https://~i',$url))return false;
  if(parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null)return false;
  if(!function_exists('wp_http_validate_url'))return false;
  return (bool)wp_http_validate_url($url);
 }
 public static function save(){
  if(!current_user_can('manage_options'))wp_die('Brak uprawnień','',['response'=>403]);
  check_admin_referer('hds3_webhooks_save');
  $url=esc_url_raw(trim((string)wp_unslash($_POST['url']??'')));
  if($url!==''&&!self::valid_url($url))wp_die('Wymagany bezpieczny publiczny adres HTTPS','',['response'=>400]);
  $prev=self::config();$secret=$prev['secret'];
  if(!$secret||!empty($_POST['rotate_secret']))$secret=bin2hex(random_bytes(32));
  $given=(string)wp_unslash($_POST['secret']??'');
  if($given!==''){if(strlen($given)<32||strlen($given)>256)wp_die('Klucz musi mieć 32–256 znaków','',['response'=>400]);$secret=$given;}
  update_option('hds3_webhook_config',['enabled'=>!empty($_POST['enabled'])&&$url!==''?1:0,'url'=>$url,'event'=>'alert.open','secret'=>$secret],false);
  wp_safe_redirect(admin_url('tools.php?page=hds3-webhooks&saved=1'));exit;
 }
 /** Event is intentionally limited to server-generated alert records. */
 public static function on_alert($alert_id,$severity){
  $cfg=self::config();if(empty($cfg['enabled'])||!self::valid_url($cfg['url']))return;
  if(!in_array($severity,['warning','critical'],true))return;
   $alert_id=absint($alert_id);
  if(!$alert_id)return;
  $throttle='hds3_webhook_alert_'. $alert_id;
  if(get_transient($throttle))return;
  $result=self::queue_event('alert.open',['alert_id'=>$alert_id,'severity'=>$severity]);
  if(!is_wp_error($result))set_transient($throttle,1,HOUR_IN_SECONDS);
 }
 public static function queue_event($type,$data){
  $cfg=self::config();if(empty($cfg['enabled'])||!self::valid_url($cfg['url']))return new WP_Error('disabled','Webhook wyłączony');
  if($type!=='alert.open'||!is_array($data))return new WP_Error('invalid','Nieobsługiwany typ');
  self::maybe_install();
  $event_id=wp_generate_uuid4();$now=current_time('mysql',true);global $wpdb;
  $ok=$wpdb->insert(self::table(),['event_id'=>$event_id,'event_type'=>$type,'status'=>'queued','created_at'=>$now,'updated_at'=>$now],['%s','%s','%s','%s','%s']);
  if(!$ok)return new WP_Error('database','Błąd rejestru');
  $job=HDS3_Automation::enqueue('webhook_delivery',['event_id'=>$event_id,'event_type'=>$type,'data'=>$data],0,0,5);
  if(is_wp_error($job))$wpdb->update(self::table(),['status'=>'failed','last_error'=>$job->get_error_code()],['event_id'=>$event_id]);
  return $job;
 }
 public static function deliver($payload,$tenant_id){
  $cfg=self::config();$id=(string)($payload['event_id']??'');
  if(!preg_match('/^[a-f0-9-]{36}$/D',$id))return new WP_Error('bad_event','Nieprawidłowe ID');
  if(empty($cfg['enabled']))return new WP_Error('disabled','Webhook wyłączony');
  if(!self::valid_url($cfg['url']))return new WP_Error('invalid_url','Adres niedozwolony');
  if(empty($cfg['secret']))return new WP_Error('no_secret','Brak klucza');
  $body=wp_json_encode(['id'=>$id,'type'=>'alert.open','created_at'=>gmdate('c'),'data'=>$payload['data']??[]]);
  $time=(string)time();$signature=hash_hmac('sha256',$time.'.'.$body,$cfg['secret']);
  $response=wp_safe_remote_post($cfg['url'],['timeout'=>8,'redirection'=>0,'blocking'=>true,'limit_response_size'=>1024,
    'headers'=>['Content-Type'=>'application/json','X-Heterodyna-Event'=>'alert.open','X-Heterodyna-Id'=>$id,'X-Heterodyna-Timestamp'=>$time,'X-Heterodyna-Signature'=>'sha256='.$signature],
    'body'=>$body]);
  $code=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
  $success=$code>=200&&$code<300;
  $err=is_wp_error($response)?$response->get_error_code():($success?'':'HTTP '.$code);
  global $wpdb;$t=self::table();
  $wpdb->query($wpdb->prepare("UPDATE $t SET attempts=attempts+1,status=%s,http_code=%d,last_error=%s,updated_at=%s WHERE event_id=%s",$success?'delivered':'retrying',$code,substr($err,0,255),current_time('mysql',true),$id));
  return $success?true:new WP_Error('delivery_failed',$err);
 }
 public static function test(){
  if(!current_user_can('manage_options'))wp_die('Brak uprawnień','',['response'=>403]);check_admin_referer('hds3_webhooks_test');
  $result=self::queue_event('alert.open',['alert_id'=>0,'severity'=>'warning','test'=>true]);
  if(is_wp_error($result)){
   wp_safe_redirect(add_query_arg(['page'=>'hds3-webhooks','queue_error'=>sanitize_key($result->get_error_code())],admin_url('tools.php')));exit;
  }
  wp_safe_redirect(admin_url('tools.php?page=hds3-webhooks&queued=1'));exit;
 }
 public static function menu(){add_management_page('Heterodyna · Webhooki','Heterodyna · Webhooki','manage_options','hds3-webhooks',[__CLASS__,'page']);}
 public static function page(){
  if(!current_user_can('manage_options'))wp_die('Brak uprawnień');
  $c=self::config();global $wpdb;$t=self::table();
  $rows=$wpdb->get_results("SELECT event_id,event_type,status,http_code,attempts,updated_at,last_error FROM $t ORDER BY id DESC LIMIT 50");
  echo '<div class="wrap"><h1>Heterodyna Cloud · Webhooki</h1><p>Webhooki są opcjonalne. Wysyłka obejmuje alerty Warning/Critical. Odbiorca musi weryfikować podpis HMAC i znacznik czasu.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('hds3_webhooks_save');
  echo '<input type="hidden" name="action" value="hds3_webhooks_save"><p><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($c['enabled']),true,false).'> Włącz webhooki</label></p>';
  echo '<p><label>Publiczny adres HTTPS <input type="url" name="url" class="regular-text" value="'.esc_attr($c['url']).'" placeholder="https://example.org/webhooks"></label></p>';
  echo '<p>Ustaw wspólny klucz HMAC (minimum 32 znaki). Po zapisaniu nie będzie wyświetlany. Wprowadź taki sam klucz w systemie odbiorcy. Rotacja unieważni poprzedni klucz.</p><p><label>Nowy klucz HMAC <input type="password" name="secret" class="regular-text" autocomplete="new-password"></label></p>';
  echo '<p><label><input type="checkbox" name="rotate_secret" value="1"> Wygeneruj nowy klucz HMAC</label></p>';
  submit_button('Zapisz ustawienia');echo '</form>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('hds3_webhooks_test');echo '<input type="hidden" name="action" value="hds3_webhooks_test">';submit_button('Dodaj test do kolejki','secondary');echo '</form>';
  echo '<h2>Historia ostatnich 50 dostarczeń</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Zdarzenie</th><th>Stan</th><th>HTTP</th><th>Próby</th><th>Ostatnia próba UTC</th><th>Błąd</th></tr></thead><tbody>';
  foreach((array)$rows as $r)echo '<tr><td>'.esc_html($r->event_id).'</td><td>'.esc_html($r->event_type).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->http_code).'</td><td>'.esc_html($r->attempts).'</td><td>'.esc_html($r->updated_at).'</td><td>'.esc_html((string)$r->last_error).'</td></tr>';
  echo '</tbody></table></div>';
 }
}
