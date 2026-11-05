<?php
if (!defined('ABSPATH')) exit;

function hds3_now_gmt() {
    return gmdate('Y-m-d H:i:s');
}

/**
 * Generowanie klucza licencyjnego z prefiksem produktu (np. SEO-XXXXXXXX).
 * Klucz ma być czytelny i spójny w całym ekosystemie.
 */
function hds3_generate_license_key(string $product = 'PRODUCT', int $body_len = 18): string {
    $product = strtoupper(trim($product));
    $product = preg_replace('/[^A-Z0-9_\-]/', '', $product);
    if ($product === '') $product = 'PRODUCT';

    // Mapa prefiksów (możesz rozszerzać bez zmiany reszty kodu).
    $map = [
        'SEO' => 'SEO',
        'PWA' => 'PWA',
        'CORE' => 'CORE',
        'SAAS' => 'SAAS',
    ];
    $prefix = $map[$product] ?? $product;

    $body_len = max(10, min(64, (int)$body_len));
    // Tylko czytelne znaki (bez I/O/1/0) – mniej pomyłek przy przepisywaniu.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $raw = '';
    for ($i = 0; $i < $body_len; $i++) {
        $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $prefix . '-' . $raw;
}

function hds3_site_host() {
    $home = home_url();
    $p = wp_parse_url($home);
    return isset($p['host']) ? strtolower($p['host']) : strtolower($home);
}

function hds3_json_encode($arr) {
    return wp_json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function hds3_json_decode($str, $default = []) {
    if (!is_string($str) || $str === '') return $default;
    $d = json_decode($str, true);
    return is_array($d) ? $d : $default;
}

function hds3_admin_url($page, array $args = []) {
    $args = array_merge(['page' => (string)$page], $args);
    return add_query_arg($args, admin_url('admin.php'));
}

function hds3_is_admin_capable() {
    return current_user_can('manage_options');
}

/**
 * Domyślny katalog/cennik dla produktu SEO (do wyrenderowania shortcode).
 * CTA domyślnie jest placeholderem (#) — w panelu możesz podstawić docelowy link.
 */
function hds3_default_catalog_seo() {
    $cta = [
        'cta_label' => 'Skontaktuj się',
        'cta_url' => '#',
    ];

    return [
        'product' => 'SEO',
        'currency' => 'PLN',
        'categories' => [
            [
                'key' => 'web',
                'title' => 'Strony www',
                'desc' => '1 klucz = 1 domena. Pakiety czasowe dopasowane do wdrożeń i utrzymania.',
                'offers' => [
                    array_merge(['label' => '1 mc', 'months' => 1, 'price_pln' => 79, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '3 mc', 'months' => 3, 'price_pln' => 219, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 399, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 699, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                ],
            ],
            [
                'key' => 'biz',
                'title' => 'Firmy',
                'desc' => 'Pakiety dla działalności i serwisów firmowych. 1 klucz = 1 domena.',
                'offers' => [
                    array_merge(['label' => '1 mc', 'months' => 1, 'price_pln' => 129, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '3 mc', 'months' => 3, 'price_pln' => 349, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 649, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 1099, 'domains' => '1 domena', 'support' => 'Aktualizacje'], $cta),
                ],
            ],
            [
                'key' => 'ag10',
                'title' => 'Agencje — do 10 domen',
                'desc' => 'Pakiet agencyjny: jedna licencja obejmuje wiele domen w limitach.',
                'offers' => [
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 1199, 'domains' => 'Do 10 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 2199, 'domains' => 'Do 10 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                ],
            ],
            [
                'key' => 'ag50',
                'title' => 'Agencje — do 50 domen',
                'desc' => 'Dla zespołów obsługujących większą liczbę instalacji.',
                'offers' => [
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 3999, 'domains' => 'Do 50 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 6499, 'domains' => 'Do 50 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                ],
            ],
            [
                'key' => 'ag100',
                'title' => 'Agencje — do 100 domen',
                'desc' => 'Model dla agencji z procesami i obsługą flotową.',
                'offers' => [
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 6999, 'domains' => 'Do 100 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 10999, 'domains' => 'Do 100 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                ],
            ],
            [
                'key' => 'ag1000',
                'title' => 'Agencje — do 1000 domen',
                'desc' => 'Największy pakiet dla sieci i wdrożeń na dużą skalę.',
                'offers' => [
                    array_merge(['label' => '6 mc', 'months' => 6, 'price_pln' => 39999, 'domains' => 'Do 1000 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                    array_merge(['label' => '12 mc', 'months' => 12, 'price_pln' => 59999, 'domains' => 'Do 1000 domen', 'support' => 'Aktualizacje, wsparcie programisty 24/7'], $cta),
                ],
            ],
        ],
    ];
}

/**
 * SEO — rejestr planów (źródło prawdy dla pakietów i limitów).
 * Uwaga: ceny są w katalogu (cennik). Tu trzymamy wyłącznie parametry licencyjne.
 */
function hds3_default_seo_plan_registry(): array {
    $out = [];

    $mk = function(string $plan_code, string $tier, int $months, int $domain_limit) {
        return [
            'plan_code' => $plan_code,
            'tier' => $tier,
            'duration_months' => $months,
            'domain_limit' => $domain_limit,
            // sensowny default: ile razy wolno aktywować/reaktywować w trakcie pakietu
            'activations_limit' => max(1, $months),
        ];
    };

    // BASIC
    foreach ([1,3,6,12] as $m) {
        $out['SEO_BASIC_'.$m.'M'] = $mk('SEO_BASIC_'.$m.'M', 'basic', $m, 1);
    }
    // PRO
    foreach ([1,3,6,12] as $m) {
        $out['SEO_PRO_'.$m.'M'] = $mk('SEO_PRO_'.$m.'M', 'pro', $m, 1);
    }

    // AGENCY (10/50/100/1000 domen)
    foreach ([10,50,100,1000] as $d) {
        foreach ([1,3,6,12] as $m) {
            $out['SEO_AGENCY'.$d.'_'.$m.'M'] = $mk('SEO_AGENCY'.$d.'_'.$m.'M', 'agency', $m, $d);
        }
    }

    return $out;
}

// Prosty generator tokenów/kluczy: alnum bez mylących znaków.
function hds3_rand_alnum(int $len = 24): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // bez 0,O,1,I
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i=0; $i<$len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}
