<?php
/**
 * The one Predis symbol inc/settings/cache.php names, declared so a test can
 * build a Predis-shaped client without installing the library.
 *
 * Own file because a namespaced declaration cannot share a file with the
 * global-namespace test class under this codebase's coding standard.
 *
 * @package blueline
 */

namespace Predis;

/**
 * Stand-in for Predis\ClientInterface.
 */
interface ClientInterface {
}
