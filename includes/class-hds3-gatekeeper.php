<?php

if (!defined('ABSPATH')) exit;



class HDS3_Gatekeeper {

    public static function init() {

        add_action('admin_init', [__CLASS__, 'maybe_block_admin'], 1);

        add_action('template_redirect', [__CLASS__, 'maybe_block_front'], 1);

    }



    private static function is_asset_request() {

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        if (!$uri) return false;

        // allow common WP admin assets and static files

        $allow = ['admin-ajax.php','admin-post.php','load-scripts.php','load-styles.php','async-upload.php','rest-api','wp-json'];

        foreach ($allow as $a) if (strpos($uri, $a) !== false) return true;

        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|eot)(\?.*)?$/i', $uri)) return true;

        return false;

    }



    private static function is_verified() {

        if (!HDS3_Settings::module_enabled('verification')) return true; // don't block if module off

        $site = hds3_site_host();

        HDS3_Kernel::ensure_verification_subject('site', $site);

        $v = HDS3_Kernel::verification_get('site', $site);

        return isset($v['status']) && $v['status'] === 'verified';

    }



    public static function maybe_block_admin() {

        if (!HDS3_Settings::get('gatekeeper_enabled','0') || HDS3_Settings::get('gatekeeper_enabled','0') !== '1') return;

        $scope = HDS3_Settings::get('gatekeeper_scope','admin');

        if ($scope !== 'admin' && $scope !== 'both') return;

        if (defined('DOING_AJAX') && DOING_AJAX) return;

        if (self::is_asset_request()) return;



        if (current_user_can('manage_options')) return;

        if (self::is_verified()) return;



        $redir = HDS3_Settings::get('gatekeeper_redirect', wp_login_url());

        wp_safe_redirect($redir);

        exit;

    }



    public static function maybe_block_front() {

        if (!HDS3_Settings::get('gatekeeper_enabled','0') || HDS3_Settings::get('gatekeeper_enabled','0') !== '1') return;

        $scope = HDS3_Settings::get('gatekeeper_scope','admin');

        if ($scope !== 'front' && $scope !== 'both') return;

        if (is_user_logged_in() && current_user_can('manage_options')) return;

        if (self::is_asset_request()) return;

        if (self::is_verified()) return;



        $redir = HDS3_Settings::get('gatekeeper_redirect', wp_login_url());

        wp_safe_redirect($redir);

        exit;

    }

}

