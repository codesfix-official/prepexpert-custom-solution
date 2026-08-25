<?php
/**
 * Parent-Child Database Abstraction Layer
 *
 * @package PrepExpertExamPapers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prep_Expert_Parent_Child_Database {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'prep_parent_children';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			parent_user_id bigint(20) unsigned NOT NULL,
			child_user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			removed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_child (child_user_id, removed_at),
			KEY parent_active (parent_user_id, removed_at),
			KEY child_lookup (child_user_id)
		) {$charset_collate};";
		dbDelta( $sql );
		update_option( 'prep_parent_child_db_version', '1.0.0', false );
	}

	public static function active_children( $parent_id ) {
		global $wpdb;
		$parent_id = absint( $parent_id );
		if ( ! $parent_id ) {
			return array();
		}
		$table = self::table_name();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT relation.id, relation.child_user_id, relation.created_at, users.display_name, users.user_email 
				FROM {$table} AS relation 
				INNER JOIN {$wpdb->users} AS users ON users.ID = relation.child_user_id 
				WHERE relation.parent_user_id = %d AND relation.removed_at IS NULL 
				ORDER BY users.display_name ASC",
				$parent_id
			),
			ARRAY_A
		);
	}

	/**
	 * Verify if a parent user is authorized to access a specific child's data.
	 *
	 * @param int $parent_id Parent User ID.
	 * @param int $child_id  Child User ID.
	 * @return bool True if active relation exists, false otherwise.
	 */
	public static function can_parent_access_child( $parent_id, $child_id ) {
		global $wpdb;
		$parent_id = absint( $parent_id );
		$child_id  = absint( $child_id );

		if ( ! $parent_id || ! $child_id ) {
			return false;
		}

		// Self-access is valid for direct student access
		if ( $parent_id === $child_id ) {
			return true;
		}

		$table = self::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE parent_user_id = %d AND child_user_id = %d AND removed_at IS NULL",
				$parent_id,
				$child_id
			)
		);

		return absint( $found ) > 0;
	}

	public static function add( $parent_id, $child_id ) {
		global $wpdb;
		$parent_id = absint( $parent_id );
		$child_id  = absint( $child_id );
		$table     = self::table_name();

		prep_expert_parent_child_write_log( 'database add started', array( 'table' => $table, 'parent_id' => $parent_id, 'child_id' => $child_id ) );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, parent_user_id FROM {$table} WHERE child_user_id = %d AND removed_at IS NULL LIMIT 1",
				$child_id
			)
		);

		if ( $existing ) {
			prep_expert_parent_child_write_log( 'database add blocked: child already linked', array( 'relation_id' => $existing->id, 'parent_id' => $existing->parent_user_id ) );
			return new WP_Error( 'child_already_linked', __( 'This child already belongs to a parent account.', 'prep-expert-exam-papers' ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'parent_user_id' => $parent_id,
				'child_user_id'  => $child_id,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			prep_expert_parent_child_write_log( 'database add failed', array( 'error' => $wpdb->last_error ? $wpdb->last_error : 'unknown database error' ) );
			return new WP_Error( 'relationship_not_saved', __( 'The child relationship could not be saved.', 'prep-expert-exam-papers' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function remove( $parent_id, $relation_id ) {
		global $wpdb;
		$updated = $wpdb->update(
			self::table_name(),
			array( 'removed_at' => current_time( 'mysql', true ) ),
			array(
				'id'             => absint( $relation_id ),
				'parent_user_id' => absint( $parent_id ),
				'removed_at'     => null,
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
		return false !== $updated && $updated > 0;
	}
}