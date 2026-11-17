<?php

// Deleted from the Plugins screen: the settings go (the secret key with them).
// Orders keep their notes and Orboni's session ids, which are the store's records.
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('woocommerce_orboni_settings');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('orboni_wc_check_order', [], 'orboni');
}
