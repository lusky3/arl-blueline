<?php
/**
 * Stand-in for WooCommerce's feature-flag reader, which the email templates call.
 *
 * @package blueline
 */

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( FeaturesUtil::class ) ) {
	/**
	 * Tests flip the email_improvements flag directly.
	 */
	class FeaturesUtil {
		/**
		 * Whether email_improvements is on.
		 *
		 * @var bool
		 */
		public static bool $email_improvements = true;

		/**
		 * Whether a feature is enabled.
		 *
		 * @param string $feature Feature id.
		 * @return bool
		 */
		public static function feature_is_enabled( string $feature ): bool {
			return 'email_improvements' === $feature && self::$email_improvements;
		}
	}
}
