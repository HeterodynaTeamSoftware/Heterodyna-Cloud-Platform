<?php
/**
 * Compatibility inventory for legacy modules, read-only, non-executing.
 * No dynamic includes, database writes, or replacement boot side effects.
 * @package HeterodynaCloud
 */
if (!defined('ABSPATH')) { exit; }
final class HDS3_Legacy_Module_Bridge {
    public static function inventory() {
        return array(
            'organizations' => array('class' => 'HDS3_Tenants', 'extension' => 'organizations', 'status' => 'legacy'),
            'plans' => array('class' => 'HDS3_Plans', 'extension' => 'plans', 'status' => 'legacy'),
            'licenses' => array('class' => 'HDS3_Billing', 'extension' => 'licenses', 'status' => 'legacy'),
            'identity' => array('class' => 'HDS3_IAM', 'extension' => 'identity', 'status' => 'legacy'),
            'subscriptions' => array('class' => 'HDS3_Subscriptions', 'extension' => 'subscriptions', 'status' => 'migrated-entrypoints'),
            'usage' => array('class' => 'HDS3_Usage', 'extension' => 'usage', 'status' => 'migrated-entrypoints'),
            'automation' => array('class' => 'HDS3_Automation', 'extension' => 'automation', 'status' => 'migrated'),
            'performance' => array('class' => 'HDS3_Performance', 'extension' => 'performance', 'status' => 'migrated'),
        );
    }
    public static function report() {
        $result = array();
        foreach (self::inventory() as $id => $item) {
            $item['loaded'] = class_exists($item['class'], false);
            $result[$id] = $item;
        }
        return $result;
    }
}
