<?php
/**
 * Test bootstrap. Defines minimal WordPress stubs so pure logic can be unit tested
 * without a WordPress install.
 *
 * @package blueline
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $t ) { return filter_var( (string) $t, FILTER_SANITIZE_URL ); }
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = null ) { return $t; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { return $value; }
}
