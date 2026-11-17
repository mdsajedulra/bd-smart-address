<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'bdsa_settings' );
delete_option( 'bdsa_version' );
