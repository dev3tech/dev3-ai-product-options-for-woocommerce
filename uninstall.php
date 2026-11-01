<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiProductOptions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3po_settings = get_option( 'dev3po_settings', array() );
if ( empty( $dev3po_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3po_usage', 'dev3po_core_cache', 'dev3po_core_jobs', 'dev3po_core_embeddings', 'dev3po_uploads' ) as $dev3po_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3po_table}" );
}
foreach ( array( 'dev3po_settings', 'dev3po_license', 'dev3po_db_version' ) as $dev3po_option ) {
	delete_option( $dev3po_option );
}
foreach ( get_posts(
	array(
		'post_type'      => 'dev3po_set',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
) as $dev3po_set_id ) {
	wp_delete_post( $dev3po_set_id, true );
}
foreach ( array( '_dev3po_fields', '_dev3po_mode', '_dev3po_exclude_sets', '_dev3po_price_overrides' ) as $dev3po_meta ) {
	delete_post_meta_by_key( $dev3po_meta );
}
$dev3po_uploads = wp_upload_dir( null, false );
if ( ! empty( $dev3po_uploads['basedir'] ) && is_dir( $dev3po_uploads['basedir'] . '/dev3po-uploads' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $dev3po_uploads['basedir'] . '/dev3po-uploads', true );
	}
}
wp_clear_scheduled_hook( 'dev3po_daily' );
delete_transient( 'dev3po_models' );
delete_site_transient( 'dev3po_update_info' );
wp_unschedule_hook( 'dev3po_run_job' );
