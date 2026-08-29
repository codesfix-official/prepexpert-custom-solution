<?php
/**
 * Parent-child account module.
 *
 * @package PrepExpertExamPapers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'prep_expert_parent_child_write_log' ) ) {
	function prep_expert_parent_child_write_log( $message, $context = array() ) {
		return;
		$base_dir = defined( 'WP_CONTENT_DIR' ) && WP_CONTENT_DIR
			? WP_CONTENT_DIR
			: rtrim( ABSPATH, '/\\' ) . '/wp-content';
		$log_dir = rtrim( $base_dir, '/\\' ) . '/prep-expert-logs';

		if ( ! is_dir( $log_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $log_dir );
			} else {
				@mkdir( $log_dir, 0755, true );
			}
		}

		$log_file = $log_dir . '/parent-child.log';
		$timestamp = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
		$line = '[' . $timestamp . '] [Prep Expert Parent Child] ' . $message;

		if ( ! empty( $context ) ) {
			$encoded_context = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) : json_encode( $context );
			if ( false !== $encoded_context ) {
				$line .= ' ' . $encoded_context;
			}
		}

		$line .= PHP_EOL;

		$handle = fopen( $log_file, 'a' );
		if ( false !== $handle ) {
			fwrite( $handle, $line );
			fclose( $handle );
		}
	}
}

require_once __DIR__ . '/class-parent-child-database.php';
require_once __DIR__ . '/class-parent-child-module.php';

if ( function_exists( 'prep_expert_parent_child_write_log' ) ) {
	prep_expert_parent_child_write_log( 'logger initialized' );
}

Prep_Expert_Parent_Child_Module::instance();
