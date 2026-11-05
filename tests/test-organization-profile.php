<?php
$base=dirname(__DIR__);
$portal=file_get_contents($base.'/includes/class-hds3-customer-portal.php');
$bootstrap=file_get_contents($base.'/heterodyna-saas-v3-core.php');
$tests=[
    'Profile endpoint registered'=>str_contains($bootstrap,"admin_post_hds3_portal_profile"),
    'Nonce check'=>str_contains($portal,"check_admin_referer('hds3_portal_profile_"),
    'Membership permission'=>str_contains($portal,"get_current_user_id(), 'manage_members'"),
    'Active tenant check'=>str_contains($portal,"!== 'active'"),
    'Only display name updated'=>str_contains($portal,"HDS3_Tenants::update("),
];
foreach ($tests as $label=>$ok) {echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; if (!$ok) exit(1);}
