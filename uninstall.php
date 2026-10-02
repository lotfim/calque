<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Calque
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'calque_links' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'calque_settings' );
delete_option( 'calque_db_version' );
delete_option( 'calque_last_error' );

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_calque\\_%' OR option_name LIKE '\\_transient\\_timeout\\_calque\\_%'"
);
