<?php
/**
 * Customer uploads: protected folder + table dev3po_uploads.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Stores files under uploads/dev3po-uploads/<year>/<month>/<random>.<ext> (direct access denied),
 * one row per file bound to the uploader's session hash.
 */
class Uploads_Repository {

	const COOKIE = 'dev3po_uid';
	const DIR    = 'dev3po-uploads';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3po_uploads';
	}

	/**
	 * CREATE TABLE for dbDelta (hooked to dev3po_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$table = self::table();
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL DEFAULT '',
			session char(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			field_id varchar(40) NOT NULL DEFAULT '',
			file_path varchar(255) NOT NULL DEFAULT '',
			file_name varchar(190) NOT NULL DEFAULT '',
			mime varchar(80) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			width int(10) unsigned NOT NULL DEFAULT 0,
			height int(10) unsigned NOT NULL DEFAULT 0,
			hash char(40) NOT NULL DEFAULT '',
			check_status varchar(12) NOT NULL DEFAULT 'none',
			check_message varchar(255) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY order_id (order_id),
			KEY created (created_at)
		) {$charset};";
		return $sql;
	}

	/**
	 * Absolute base dir (created with deny rules on first use).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Session hash of the current visitor (cookie is set on first upload).
	 *
	 * @param bool $create Create the cookie when missing.
	 * @return string
	 */
	public static function session( $create = false ) {
		$uid = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : '';
		if ( '' === $uid && $create ) {
			$uid                     = wp_generate_password( 32, false, false );
			$_COOKIE[ self::COOKIE ] = $uid;
			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, $uid, time() + 2 * DAY_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			}
		}
		return '' === $uid ? '' : hash_hmac( 'sha256', $uid . '|' . get_current_user_id(), wp_salt( 'auth' ) );
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $row Row.
	 * @return array Row with id/token.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row = array_merge(
			array(
				'token'         => wp_generate_password( 32, false, false ),
				'session'       => '',
				'user_id'       => get_current_user_id(),
				'product_id'    => 0,
				'field_id'      => '',
				'file_path'     => '',
				'file_name'     => '',
				'mime'          => '',
				'size'          => 0,
				'width'         => 0,
				'height'        => 0,
				'hash'          => '',
				'check_status'  => 'none',
				'check_message' => '',
				'order_id'      => 0,
				'created_at'    => current_time( 'mysql', true ),
			),
			$row
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row['id'] = (int) $wpdb->insert_id;
		return $row;
	}

	/**
	 * Updates a row.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Finds by token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", (string) $token ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Finds by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Resolver for Values::parse: the token must belong to this session, product and field.
	 *
	 * @param int $product_id Product.
	 * @return callable
	 */
	public static function resolver( $product_id ) {
		$session = self::session();
		return static function ( $token, $field ) use ( $session, $product_id ) {
			$row = self::by_token( $token );
			if ( ! $row || '' === $session || ! hash_equals( $row['session'], $session ) || (int) $row['product_id'] !== (int) $product_id || $row['field_id'] !== $field['id'] || 'fail' === $row['check_status'] ) {
				return null;
			}
			return array(
				'token' => $row['token'],
				'name'  => $row['file_name'],
			);
		};
	}

	/**
	 * Marks uploads as attached to an order.
	 *
	 * @param string[] $tokens   Tokens.
	 * @param int      $order_id Order.
	 */
	public static function attach( array $tokens, $order_id ) {
		global $wpdb;
		$table = self::table();
		foreach ( $tokens as $token ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET order_id = %d WHERE token = %s", (int) $order_id, (string) $token ) );
		}
	}

	/**
	 * Absolute path of a row's file.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function path( array $row ) {
		$path = self::base_dir() . '/' . ltrim( str_replace( array( '..', '\\' ), '', $row['file_path'] ), '/' );
		return $path;
	}

	/**
	 * Deletes a row and its file.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$row = self::find( $id );
		if ( ! $row ) {
			return false;
		}
		$path = self::path( $row );
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Admin list.
	 *
	 * @param int    $page   Page.
	 * @param int    $per    Per page.
	 * @param string $status all|attached|pending|fail|warn.
	 * @return array { items, total }
	 */
	public static function list( $page = 1, $per = 20, $status = 'all' ) {
		global $wpdb;
		$table  = self::table();
		$where  = '1=1';
		$params = array();
		if ( 'attached' === $status ) {
			$where = 'order_id > 0';
		} elseif ( 'pending' === $status ) {
			$where = 'order_id = 0';
		} elseif ( in_array( $status, array( 'fail', 'warn', 'ok', 'unchecked' ), true ) ) {
			$where    = 'check_status = %s';
			$params[] = $status;
		}
		$offset = ( max( 1, $page ) - 1 ) * $per;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, $offset ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'public_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Row for the admin API (no paths).
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function public_row( array $row ) {
		return array(
			'id'            => (int) $row['id'],
			'file_name'     => $row['file_name'],
			'mime'          => $row['mime'],
			'size'          => (int) $row['size'],
			'width'         => (int) $row['width'],
			'height'        => (int) $row['height'],
			'product_id'    => (int) $row['product_id'],
			'product'       => get_the_title( (int) $row['product_id'] ),
			'field_id'      => $row['field_id'],
			'check_status'  => $row['check_status'],
			'check_message' => $row['check_message'],
			'order_id'      => (int) $row['order_id'],
			'created_at'    => mysql_to_rfc3339( $row['created_at'] ),
			'url'           => self::admin_url( $row ),
		);
	}

	/**
	 * Admin download/preview URL (nonce + capability checked on download).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function admin_url( array $row ) {
		return add_query_arg(
			array(
				'action'   => 'dev3po_file',
				'id'       => (int) $row['id'],
				'_wpnonce' => wp_create_nonce( 'dev3po_file_' . (int) $row['id'] ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Removes files never attached to an order after the retention period.
	 *
	 * @param int $days Days.
	 * @return int Deleted.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = 0 AND created_at < %s LIMIT 500", $cutoff ) );
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
		return count( $ids );
	}

	/**
	 * Counts for the dashboard.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT check_status, COUNT(*) AS n FROM {$table} GROUP BY check_status", ARRAY_A );
		$out  = array( 'total' => 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['check_status'] ] = (int) $row['n'];
			$out['total']               += (int) $row['n'];
		}
		return $out;
	}
}
