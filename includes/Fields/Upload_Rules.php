<?php
/**
 * File-upload whitelist.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Allowed extensions and their MIME types. SVG, HTML and scripts are never accepted.
 */
class Upload_Rules {

	const MIMES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'pdf'  => 'application/pdf',
		'txt'  => 'text/plain',
		'zip'  => 'application/zip',
	);

	const IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );

	/**
	 * Extensions a shop may allow.
	 *
	 * @return string[]
	 */
	public static function allowed_extensions() {
		/**
		 * Extensions a file field may accept (lowercase, no dot). Never add svg/html/php.
		 *
		 * @param string[] $extensions Extensions.
		 */
		$exts = (array) apply_filters( 'dev3po_upload_extensions', array_keys( self::MIMES ) );
		return array_values( array_diff( array_map( 'strtolower', $exts ), array( 'svg', 'svgz', 'html', 'htm', 'php', 'phtml', 'phar', 'js', 'exe' ) ) );
	}

	/**
	 * MIME map for wp_check_filetype_and_ext.
	 *
	 * @param string[] $exts Extensions.
	 * @return array
	 */
	public static function mimes_for( array $exts ) {
		$out = array();
		foreach ( $exts as $ext ) {
			if ( isset( self::MIMES[ $ext ] ) ) {
				$out[ $ext ] = self::MIMES[ $ext ];
			}
		}
		return $out;
	}
}
