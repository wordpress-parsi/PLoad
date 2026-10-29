<?php
/**
 * Image engines. Every engine writes to a temp file first, the output is
 * validated, and only then renamed into place, so a half-written or broken
 * WebP is never left where a browser could be served it.
 */

defined( 'ABSPATH' ) || exit;

abstract class Pload_Driver {

	/** @return string imagick | gd */
	abstract public function name();

	/**
	 * Engine-specific conversion into $tmp.
	 *
	 * @return string Empty on success, error code or message otherwise.
	 */
	abstract protected function convert( $src, $tmp, $quality, $mime );

	/**
	 * @return string Empty on success, error otherwise.
	 */
	public function make( $src, $dest, $quality, $mime ) {
		$tmp = $dest . '.ploadtmp';
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		try {
			$error = $this->convert( $src, $tmp, (int) $quality, $mime );
		} catch ( Throwable $e ) {
			$error = $e->getMessage();
		}

		if ( '' === $error ) {
			$error = self::validate_webp( $tmp );
		}
		if ( '' !== $error ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $error;
		}
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic move inside uploads; WP_Filesystem may need FTP credentials that background runs do not have. Failure is handled.
			wp_delete_file( $tmp );
			return 'rename_failed';
		}
		return '';
	}

	/**
	 * Checks the RIFF container. Old libgd builds write WebP files whose
	 * declared length is off by one; browsers refuse them even though
	 * imagewebp() reported success.
	 */
	public static function validate_webp( $file ) {
		clearstatcache( true, $file );
		if ( ! is_file( $file ) ) {
			return 'missing';
		}
		$size = filesize( $file );
		if ( $size < 30 ) {
			return 'empty';
		}
		$head = file_get_contents( $file, false, null, 0, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the first 12 bytes of a local file to validate it.
		if ( ! is_string( $head ) || 'RIFF' !== substr( $head, 0, 4 ) || 'WEBP' !== substr( $head, 8, 4 ) ) {
			return 'bad_header';
		}
		$declared = unpack( 'V', substr( $head, 4, 4 ) );
		if ( ! $declared || ( (int) $declared[1] + 8 ) !== (int) $size ) {
			return 'bad_length';
		}
		return '';
	}
}

final class Pload_Driver_GD extends Pload_Driver {

	public function name() {
		return 'gd';
	}

	protected function convert( $src, $tmp, $quality, $mime ) {
		if ( 'image/png' === $mime ) {
			$im = @imagecreatefrompng( $src );
			if ( $im ) {
				// imagewebp() refuses palette images; alpha must be kept explicitly.
				if ( ! imageistruecolor( $im ) ) {
					imagepalettetotruecolor( $im );
				}
				imagealphablending( $im, false );
				imagesavealpha( $im, true );
			}
		} else {
			$im = @imagecreatefromjpeg( $src );
		}

		if ( ! $im ) {
			return 'decode_failed';
		}

		$ok = @imagewebp( $im, $tmp, $quality );
		imagedestroy( $im );

		return $ok ? '' : 'encode_failed';
	}
}

final class Pload_Driver_Imagick extends Pload_Driver {

	public function name() {
		return 'imagick';
	}

	protected function convert( $src, $tmp, $quality, $mime ) {
		$im = new Imagick();
		$im->readImage( $src );

		if ( $im->getNumberImages() > 1 ) {
			$im->clear();
			return 'animated';
		}

		// Strip metadata but keep the colour profile, otherwise non-sRGB
		// photos shift colour after conversion.
		$icc = array();
		try {
			$icc = $im->getImageProfiles( 'icc', true );
		} catch ( Throwable $e ) {
			$icc = array();
		}
		$im->stripImage();
		if ( ! empty( $icc['icc'] ) ) {
			$im->profileImage( 'icc', $icc['icc'] );
		}

		$im->setImageFormat( 'webp' );
		$im->setImageCompressionQuality( $quality );
		$im->setOption( 'webp:method', '4' );
		$im->writeImage( 'webp:' . $tmp );
		$im->clear();

		return '';
	}
}

final class Pload_Drivers {

	/** @return Pload_Driver|null */
	public static function make( $name ) {
		if ( 'imagick' === $name && class_exists( 'Imagick' ) ) {
			return new Pload_Driver_Imagick();
		}
		if ( 'gd' === $name && function_exists( 'imagewebp' ) ) {
			return new Pload_Driver_GD();
		}
		return null;
	}
}
