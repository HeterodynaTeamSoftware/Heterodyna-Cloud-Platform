<?php

if (!defined('ABSPATH')) exit;



class HDS3_Kernel {

    private static $booted = false;



    public static function boot() {

        if (self::$booted) return;

        self::$booted = true;



        // Ensure defaults

        if (class_exists('HDS3_Settings')) {

            // init_defaults called on activation; but in case DB existed, ensure missing ones.

            HDS3_Settings::init_defaults();

        }



        // Vault base folder (best-effort)

        if (class_exists('HDS3_Vault')) {

            HDS3_Vault::ensure_base();

        }

    }



    public static function ensure_verification_subject($type, $id) {

        global $wpdb;

        $vtable = HDS3_DB::table('verification');

        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $vtable WHERE subject_type=%s AND subject_id=%s LIMIT 1", $type, $id));

        if (!$row) {

            $wpdb->insert($vtable, [

                'subject_type' => $type,

                'subject_id' => $id,

                'status' => 'unverified',

                'current_step' => 'start',

                'meta' => null,

                'updated_at' => hds3_now_gmt(),

            ]);

        }

        self::ensure_steps($type, $id);

    }



    public static function ensure_steps($type, $id) {

        global $wpdb;

        $stable = HDS3_DB::table('verification_steps');

        $steps = hds3_json_decode(HDS3_Settings::get('verification_steps', ''), ['start','email','phone','docs','face','done']);

        foreach ($steps as $s) {

            $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM $stable WHERE subject_type=%s AND subject_id=%s AND step=%s LIMIT 1", $type, $id, $s));

            if (!$row) {

                $wpdb->insert($stable, [

                    'subject_type' => $type,

                    'subject_id' => $id,

                    'step' => $s,

                    'is_done' => 0,

                    'done_at' => null,

                    'updated_at' => hds3_now_gmt(),

                ]);

            }

        }

    }



    public static function verification_get($type, $id) {

        global $wpdb;

        $vtable = HDS3_DB::table('verification');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $vtable WHERE subject_type=%s AND subject_id=%s LIMIT 1", $type, $id), ARRAY_A);

    }



    public static function verification_steps($type, $id) {

        global $wpdb;

        $stable = HDS3_DB::table('verification_steps');

        return $wpdb->get_results($wpdb->prepare("SELECT step,is_done,done_at,updated_at FROM $stable WHERE subject_type=%s AND subject_id=%s ORDER BY id ASC", $type, $id), ARRAY_A);

    }



    public static function verification_complete_step($type, $id, $step) {

        global $wpdb;

        self::ensure_verification_subject($type, $id);



        $stable = HDS3_DB::table('verification_steps');

        $wpdb->query($wpdb->prepare(

            "UPDATE $stable SET is_done=1, done_at=IFNULL(done_at,%s), updated_at=%s WHERE subject_type=%s AND subject_id=%s AND step=%s",

            hds3_now_gmt(), hds3_now_gmt(), $type, $id, $step

        ));



        // find next step

        $steps = self::verification_steps($type, $id);

        $next = 'done';

        foreach ($steps as $st) {

            if (empty($st['is_done'])) { $next = $st['step']; break; }

        }



        $vtable = HDS3_DB::table('verification');

        $status = ($next === 'done') ? 'verified' : 'in_progress';

        $wpdb->query($wpdb->prepare(

            "UPDATE $vtable SET current_step=%s, status=%s, updated_at=%s WHERE subject_type=%s AND subject_id=%s",

            $next, $status, hds3_now_gmt(), $type, $id

        ));

        return ['status' => $status, 'current_step' => $next];

    }



    public static function verification_set_verified($type, $id) {

        // mark all done

        global $wpdb;

        self::ensure_verification_subject($type, $id);

        $stable = HDS3_DB::table('verification_steps');

        $wpdb->query($wpdb->prepare(

            "UPDATE $stable SET is_done=1, done_at=IFNULL(done_at,%s), updated_at=%s WHERE subject_type=%s AND subject_id=%s",

            hds3_now_gmt(), hds3_now_gmt(), $type, $id

        ));

        $vtable = HDS3_DB::table('verification');

        $wpdb->query($wpdb->prepare(

            "UPDATE $vtable SET current_step=%s, status=%s, updated_at=%s WHERE subject_type=%s AND subject_id=%s",

            'done', 'verified', hds3_now_gmt(), $type, $id

        ));

    }

}

