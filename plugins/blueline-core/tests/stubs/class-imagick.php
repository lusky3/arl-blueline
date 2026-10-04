<?php
/**
 * Recording stand-in for Imagick (the real extension is not installed in CI), used by the player-photo tests.
 *
 * @package blueline-core
 */

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the camelCase API of the real Imagick class.

if ( ! class_exists( 'Imagick' ) ) {
	/**
	 * Recording stand-in for Imagick (the real extension is not installed in CI).
	 * Every construction with a path is appended to $GLOBALS['bl_core_test_imagick_opened'].
	 */
	class Imagick {

		const ORIENTATION_TOPLEFT = 1;

		/**
		 * Profiles on the image, name => bytes.
		 *
		 * @var array<string, string>
		 */
		public array $profiles = array(
			'exif' => 'GPS',
			'icc'  => 'colour',
			'xmp'  => 'xmp',
			'iptc' => 'iptc',
		);

		/**
		 * Recorded method calls.
		 *
		 * @var array
		 */
		public array $calls = array();

		/**
		 * Constructor.
		 *
		 * @param string $path Path to open, if any.
		 */
		public function __construct( $path = '' ) {
			if ( '' !== $path ) {
				$GLOBALS['bl_core_test_imagick_opened'][] = $path;
			}
		}

		/**
		 * Profiles by name.
		 *
		 * @return array<string, string>
		 */
		public function getImageProfiles() {
			return $this->profiles;
		}

		/**
		 * Remove a profile.
		 *
		 * @param string $name Profile name.
		 * @return bool
		 */
		public function removeImageProfile( $name ) {
			unset( $this->profiles[ $name ] );
			$this->calls[] = array( 'remove', $name );

			return true;
		}

		/**
		 * Record the orientation reset.
		 *
		 * @param int $orientation Orientation.
		 * @return bool
		 */
		public function setImageOrientation( $orientation ) {
			$this->calls[] = array( 'orientation', $orientation );

			return true;
		}

		/**
		 * Record the write.
		 *
		 * @param string $path Destination.
		 * @return bool
		 */
		public function writeImage( $path ) {
			$this->calls[] = array( 'write', $path );

			return true;
		}

		/**
		 * Record the release.
		 *
		 * @return bool
		 */
		public function clear() {
			$this->calls[] = array( 'clear' );

			return true;
		}
	}
}
