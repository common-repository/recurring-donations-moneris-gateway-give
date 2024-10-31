<?php
/**
 * Plugin Name: Recurring Donations - Moneris gateway for Give 
 * Plugin URI:  https://github.com/alexsmithbr/give-recurring-moneris
 * Description: Enable recurring payments for Moneris gateway in the GiveWP donation plugin.
 * Version:     1.0.2
 * Author:      Alex Smith
 * Author URI:  https://profiles.wordpress.org/alexsmithbr/
 * Text Domain: give-recurring
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GIVE_RECURRING_MONERIS_PLUGIN_DIR' ) ) {
    define( 'GIVE_RECURRING_MONERIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

//Hooks
register_activation_hook ( __FILE__, 'give_recurring_moneris_activate' );
register_deactivation_hook ( __FILE__, 'give_recurring_moneris_deactivate' );

function give_recurring_moneris_activate() {
}

function give_recurring_moneris_deactivate() {
}

function give_recurring_moneris_gateway_setup($classes) {
    $classes['moneris'] = 'Give_Recurring_Moneris';

    give_recurring_moneris_includes();

    return $classes;
}
add_filter('give_recurring_available_gateways', 'give_recurring_moneris_gateway_setup');

function give_recurring_moneris_includes() {
    // Bailout, if moneris payment methods is inactive.
    $settings = give_get_settings();
    $gateways = isset( $settings['gateways'] ) ? $settings['gateways'] : [];

    $active = false;
    if ( array_key_exists('moneris', $gateways) ) {
        if ( $gateways['moneris'] == "1" ) {
            $active = true;
        }
    }

    if ( !$active ) {
        return;
    }

    require_once GIVE_RECURRING_MONERIS_PLUGIN_DIR . 'includes/gateways/give-recurring-moneris.php';
}

