<?php

if (!defined('ABSPATH')) exit;



/**

 * Legacy / duplikaty: wymuszone wyłączanie.

 * Cel: po konsolidacji nie pozwolić, aby stare wtyczki wracały (np. po auto-aktualizacji lub ręcznym włączeniu).

 */

class HDS3_Legacy {

    /**

     * Jeżeli w ustawieniach jest lista slugów do wymuszonego wyłączenia, a są aktywne – dezaktywuj.

     * Wykonujemy to wcześnie, zanim zainicjalizuje się reszta HDS3.

     */

    public static function maybe_force_disable() {

        if (!function_exists('get_option')) return;

        if (!function_exists('deactivate_plugins')) {

            require_once ABSPATH . 'wp-admin/includes/plugin.php';

        }

        $slugs = HDS3_Settings::get('force_disable_plugins', []);

        if (is_string($slugs)) {

            $decoded = json_decode($slugs, true);

            if (is_array($decoded)) $slugs = $decoded;

        }

        if (!is_array($slugs) || empty($slugs)) return;



        $active = get_option('active_plugins', []);

        if (!is_array($active) || empty($active)) return;



        $to_deactivate = [];

        foreach ($active as $af) {

            foreach ($slugs as $slug) {

                $slug = sanitize_text_field((string)$slug);

                if (!$slug) continue;

                if ($slug === HDS3_SLUG) continue;

                if (strpos($af, $slug . '/') === 0) {

                    $to_deactivate[] = $af;

                }

            }

        }



        if (!empty($to_deactivate)) {

            deactivate_plugins($to_deactivate);

            if (class_exists('HDS3_Logger')) {

                HDS3_Logger::event('cleanup', 'warning', 'Wymuszone wyłączenie legacy wtyczek', [

                    'plugins' => $to_deactivate,

                ]);

            }

        }

    }

}

