<?php
/**
 * Stand-in for core's Imagick image editor, used by the player-photo tests.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
	/**
	 * Stand-in for core's Imagick editor: a protected $image handle (as in
	 * core) plus recording rotate/save.
	 */
	class WP_Image_Editor_Imagick {

		/**
		 * The loaded Imagick object (protected, as in core).
		 *
		 * @var Imagick|null
		 */
		protected $image = null;

		/**
		 * Recorded calls.
		 *
		 * @var array
		 */
		public array $calls = array();

		/**
		 * Constructor.
		 *
		 * @param Imagick|null $image Loaded image.
		 */
		public function __construct( $image = null ) {
			$this->image = $image;
		}

		/**
		 * Record the rotate.
		 *
		 * @return bool
		 */
		public function maybe_exif_rotate() {
			$this->calls[] = 'rotate';

			return false;
		}

		/**
		 * Record the save, with the profile names still on the handle at that moment.
		 *
		 * @param string $path Destination.
		 * @param string $mime MIME type.
		 * @return array
		 */
		public function save( $path, $mime ) {
			$this->calls[] = array(
				'save',
				$path,
				$mime,
				null === $this->image ? array() : array_keys( $this->image->profiles ),
			);

			return array( 'path' => $path );
		}
	}
}
