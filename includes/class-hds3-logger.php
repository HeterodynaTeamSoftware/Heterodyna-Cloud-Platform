<?php

if (!defined('ABSPATH')) exit;



class HDS3_Logger {

    public static function event($type, $level, $message = '', $payload = null, $site = '') {

        global $wpdb;

        $table = HDS3_DB::table('events');

        $site = $site ? $site : hds3_site_host();

        $wpdb->insert($table, [

            'site' => $site,

            'type' => substr((string)$type, 0, 64),

            'level' => substr((string)$level, 0, 16),

            'message' => $message,

            'payload' => is_string($payload) ? $payload : ($payload ? hds3_json_encode($payload) : null),

            'created_at' => hds3_now_gmt(),

        ]);

    }

}

