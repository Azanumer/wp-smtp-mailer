<?php
/**
 * Uninstall: remove the plugin's options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wsm_smtp_settings' );
delete_option( 'wsm_last_result' );
