<?php
/**
 * Update a Code Snippets row from a file. Code Snippets stores PHP WITHOUT the opening tag.
 * Usage: wp eval-file deploy-snippet.php <id> <path-to-code-file>
 */
global $wpdb, $argv;

// wp-cli's $argv starts with its own binary path, so take the two trailing positionals.
$args = array_values( array_filter( (array) $argv, function ( $a ) {
	return '' !== $a && '-' !== substr( $a, 0, 1 );
} ) );
$args = array_slice( $args, -2 );

$id   = isset( $args[0] ) ? (int) $args[0] : 0;
$file = isset( $args[1] ) ? $args[1] : '';

if ( ! $id || ! $file || ! file_exists( $file ) ) {
	echo "usage: eval-file deploy-snippet.php <id> <file>  (got id=", $id, " file=", $file, ")\n";
	return;
}

$code = file_get_contents( $file );
$code = preg_replace( '/^<\?php\s*/', '', $code ); // just in case

$table = $wpdb->prefix . 'snippets';
$before = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, active, scope, LENGTH(code) AS len FROM {$table} WHERE id = %d", $id ) );
if ( ! $before ) {
	echo "snippet ", $id, " not found\n";
	return;
}
echo "before: id=", $before->id, " name=", $before->name, " active=", $before->active, " scope=", $before->scope, " bytes=", $before->len, "\n";

$ok = $wpdb->update( $table, array( 'code' => $code ), array( 'id' => $id ) );
echo "update returned: ", var_export( $ok, true ), "\n";

$after = $wpdb->get_row( $wpdb->prepare( "SELECT LENGTH(code) AS len, code FROM {$table} WHERE id = %d", $id ) );
echo "after bytes: ", $after->len, "\n";
echo "byte-identical to file: ", var_export( $after->code === $code, true ), "\n";
echo "md5 stored: ", md5( $after->code ), "\n";
echo "md5 file:   ", md5( $code ), "\n";

wp_cache_flush();
echo "object cache flushed\n";
