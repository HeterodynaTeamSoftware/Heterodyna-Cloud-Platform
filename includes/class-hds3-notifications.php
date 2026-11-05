<?php
/** Heterodyna Cloud: opt-in alert email notifications, with delivery attempt history. */
if (!defined('ABSPATH')) exit;
final class HDS3_Notifications {
 const SCHEMA='1';
 private static function table(){return HDS3_DB::table('notification_attempts');}
 private static function check(){if(!current_user_can('manage_options')) wp_die('Brak uprawnień','',['response'=>403]);}
 public static function config(){
  $raw=get_option('hds3_notification_config',[]);if(!is_array($raw))$raw=[];
  return array_merge(['enabled'=>0,'recipient'=>get_option('admin_email'),'min_severity'=>'warning','cooldown_hours'=>6],array_intersect_key($raw,array_flip(['enabled','recipient','min_severity','cooldown_hours'])));
 }
 public static function init(){
  add_action('admin_menu',[__CLASS__,'menu']);
  add_action('admin_post_hds3_notification_save',[__CLASS__,'save']);
  add_action('hds3_monitor_hourly',[__CLASS__,'deliver'],30);
 }
 public static function maybe_install(){if(get_option('hds3_notifications_schema')!==self::SCHEMA)self::install();}
 public static function install(){
  global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $table=self::table();$collate=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $table (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   alert_id bigint(20) unsigned NOT NULL,
   recipient varchar(191) NOT NULL,
   severity varchar(16) NOT NULL,
   status varchar(16) NOT NULL,
   attempted_at datetime NOT NULL,
   message text NULL,
   PRIMARY KEY  (id),
   KEY alert_time (alert_id,attempted_at),
   KEY status_time (status,attempted_at)
  ) $collate;");
  if(empty($wpdb->last_error)) update_option('hds3_notifications_schema',self::SCHEMA,false);
 }
 public static function save(){
  self::check();check_admin_referer('hds3_notification_save');
  $email=sanitize_email(wp_unslash($_POST['recipient']??''));
  if(!is_email($email)) wp_die('Nieprawidłowy adres e-mail','',['response'=>400]);
  $severity=sanitize_key(wp_unslash($_POST['min_severity']??'warning'));
  if(!in_array($severity,['warning','critical'],true))$severity='warning';
  update_option('hds3_notification_config',[
   'enabled'=>isset($_POST['enabled'])?1:0,
   'recipient'=>$email,
   'min_severity'=>$severity,
   'cooldown_hours'=>max(1,min(168,absint($_POST['cooldown_hours']??6)))
  ],false);
  wp_safe_redirect(admin_url('tools.php?page=hds3-notifications&saved=1'));exit;
 }
 /** Runs only through the existing hourly monitor. No user-controlled message bodies. */
 public static function deliver(){
  $cfg=self::config();if(empty($cfg['enabled']) || !is_email($cfg['recipient']))return;
  self::maybe_install();global $wpdb;
  $alerts=HDS3_DB::table('alerts');$table=self::table();
  $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($alerts)));
  if($exists!==$alerts)return;
  $allowed=$cfg['min_severity']==='critical'?"AND severity='critical'":"AND severity IN ('warning','critical')";
  $rows=$wpdb->get_results("SELECT id,severity,title,details,last_seen FROM `$alerts` WHERE status='open' $allowed ORDER BY last_seen DESC LIMIT 20",ARRAY_A);
  if(!is_array($rows))return;
  $cutoff=gmdate('Y-m-d H:i:s',time()-max(1,(int)$cfg['cooldown_hours'])*3600);
  foreach($rows as $a){
   $id=(int)$a['id'];
   $recent=$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE alert_id=%d AND attempted_at >= %s ORDER BY id DESC LIMIT 1",$id,$cutoff));
   if($recent)continue;
   // Record a reservation BEFORE delivery: avoids repeated sends on overlapping cron runs.
   $now=current_time('mysql',true);
   $wpdb->insert($table,['alert_id'=>$id,'recipient'=>$cfg['recipient'],'severity'=>$a['severity'],'status'=>'attempted','attempted_at'=>$now,'message'=>'Oczekuje na wynik wp_mail'],['%d','%s','%s','%s','%s','%s']);
   $attempt=(int)$wpdb->insert_id;if(!$attempt)continue;
   $subject='[Heterodyna Cloud] '.strtoupper($a['severity']).': '.$a['title'];
   $body="Alert #$id\nPoziom: {$a['severity']}\nTytuł: {$a['title']}\nOpis: {$a['details']}\n\nPowiadomienie wygenerowane automatycznie przez Heterodyna Cloud.";
   $sent=wp_mail($cfg['recipient'],$subject,$body);
   $wpdb->update($table,['status'=>$sent?'accepted':'failed','message'=>$sent?'WordPress zaakceptował wiadomość do wysyłki (dostarczenie niepotwierdzone)':'wp_mail zwróciło błąd'],['id'=>$attempt],['%s','%s'],['%d']);
  }
 }
 public static function menu(){add_management_page('Heterodyna Cloud · Powiadomienia','Heterodyna · Powiadomienia','manage_options','hds3-notifications',[__CLASS__,'page']);}
 public static function page(){
  self::check();self::maybe_install();global $wpdb;$cfg=self::config();$table=self::table();
  $rows=$wpdb->get_results("SELECT alert_id,recipient,severity,status,attempted_at,message FROM `$table` ORDER BY id DESC LIMIT 50",ARRAY_A);
  echo '<div class="wrap"><h1>Heterodyna Cloud · Powiadomienia</h1>';
  echo '<p>Wysyłka e-mail jest domyślnie wyłączona. Historia potwierdza próbę przekazania do WordPressa, a nie dostarczenie do skrzynki.</p>';
  if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Ustawienia zapisane.</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:680px;background:#fff;padding:24px;border:1px solid #dde4ee;border-radius:12px">';
  echo '<input type="hidden" name="action" value="hds3_notification_save">';wp_nonce_field('hds3_notification_save');
  echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked($cfg['enabled'],1,false).'> Włącz powiadomienia e-mail o otwartych alertach</label></p>';
  echo '<p><label>Adres odbiorcy <input class="regular-text" type="email" required name="recipient" value="'.esc_attr($cfg['recipient']).'"></label></p>';
  echo '<p><label>Minimalna ważność <select name="min_severity"><option value="warning" '.selected($cfg['min_severity'],'warning',false).'>Warning i Critical</option><option value="critical" '.selected($cfg['min_severity'],'critical',false).'>Tylko Critical</option></select></label></p>';
  echo '<p><label>Odstęp między próbami dla jednego alertu (godziny) <input type="number" min="1" max="168" name="cooldown_hours" value="'.esc_attr($cfg['cooldown_hours']).'"></label></p>';
  submit_button('Zapisz ustawienia');echo '</form>';
  echo '<h2>Ostatnie 50 prób</h2><table class="widefat striped"><thead><tr><th>Alert</th><th>Odbiorca</th><th>Ważność</th><th>Status</th><th>Próba (UTC)</th><th>Wynik</th></tr></thead><tbody>';
  if(!$rows) echo '<tr><td colspan="6">Brak prób wysyłki.</td></tr>';
  foreach((array)$rows as $r){echo '<tr>';foreach(['alert_id','recipient','severity','status','attempted_at','message'] as $k)echo '<td>'.esc_html($r[$k]).'</td>';echo '</tr>';}
  echo '</tbody></table></div>';
 }
}
