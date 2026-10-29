<?php
/**
 * Detects other image optimizers. Two optimizers can compress the same
 * image twice or fight over how WebP is delivered.
 */

defined( 'ABSPATH' ) || exit;

final class Pload_Conflicts {

	/** plugin file => name */
	const KNOWN = array(
		'ewww-image-optimizer/ewww-image-optimizer.php'           => 'EWWW Image Optimizer',
		'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php' => 'EWWW Image Optimizer Cloud',
		'wp-smushit/wp-smush.php'                                 => 'Smush',
		'wp-smush-pro/wp-smush.php'                               => 'Smush Pro',
		'shortpixel-image-optimiser/wp-shortpixel.php'            => 'ShortPixel',
		'shortpixel-adaptive-images/short-pixel-ai.php'           => 'ShortPixel Adaptive Images',
		'imagify/imagify.php'                                     => 'Imagify',
		'optimole-wp/optimole-wp.php'                             => 'Optimole',
		'tiny-compress-images/tiny-compress-images.php'           => 'TinyPNG',
		'webp-converter-for-media/webp-converter-for-media.php'   => 'Converter for Media',
		'webp-express/webp-express.php'                           => 'WebP Express',
		'webp-uploads/load.php'                                   => 'Modern Image Formats',
		'robin-image-optimizer/robin-image-optimizer.php'         => 'Robin Image Optimizer',
	);

	/** @return string[] Names of active conflicting plugins. */
	public static function found() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();
		foreach ( self::KNOWN as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $name;
			}
		}
		// LiteSpeed Cache is only a conflict when its WebP/AVIF replacement is on.
		if ( is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) && get_option( 'litespeed.conf.img_optm-webp' ) ) {
			$found[] = 'LiteSpeed Cache (WebP/AVIF replacement)';
		}
		return $found;
	}
}
