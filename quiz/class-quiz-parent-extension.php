<?php
/**
 * Parent purchases and AYS Quiz Maker child access/reporting.
 *
 * @package PrepExpertExamPapers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prep_Expert_Quiz_Parent_Extension {

	const ENROLLED_META = '_enrolled_quiz_ids';

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'enrol_child_quizzes' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enrol_child_quizzes' ), 30 );
		add_filter( 'woocommerce_customer_bought_product', array( __CLASS__, 'allow_child_quiz_product_access' ), 20, 4 );
	}

	/** Allow AYS/WooCommerce access checks to recognize a quiz assigned to a child. */
	public static function allow_child_quiz_product_access( $bought, $customer_email, $user_id, $product_id ) {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );
		if ( $bought || ! $user_id || ! $product_id || ! class_exists( 'Prep_Expert_Parent_Child_Database' ) ) {
			return $bought;
		}

		$parent_id = Prep_Expert_Parent_Child_Database::get_parent_by_child( $user_id );
		if ( ! $parent_id ) {
			return $bought;
		}

		foreach ( self::quiz_ids_for_product( $product_id ) as $quiz_id ) {
			$assigned = get_user_meta( $user_id, self::ENROLLED_META, true );
			if ( is_array( $assigned ) && in_array( $quiz_id, array_map( 'absint', $assigned ), true ) ) {
				return true;
			}
		}
		return $bought;
	}

	/** Assign AYS quizzes linked to products in a paid order to its selected child. */
	public static function enrol_child_quizzes( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'Prep_Expert_Parent_Child_Database' ) ) {
			return;
		}

		$order      = wc_get_order( absint( $order_id ) );
		$child_id   = $order ? absint( $order->get_meta( '_enrolled_child_user_id' ) ) : 0;
		$parent_id  = $order ? absint( $order->get_customer_id() ) : 0;
		$quiz_ids   = $order ? self::quiz_ids_for_order( $order ) : array();

		if ( ! $order || ! $parent_id || ! $child_id || $child_id === $parent_id || empty( $quiz_ids ) || ! Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_id, $child_id ) ) {
			return;
		}

		$assigned = get_user_meta( $child_id, self::ENROLLED_META, true );
		$assigned = is_array( $assigned ) ? array_map( 'absint', $assigned ) : array();
		update_user_meta( $child_id, self::ENROLLED_META, array_values( array_unique( array_merge( $assigned, $quiz_ids ) ) ) );
	}

	private static function quiz_ids_for_order( $order ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = absint( $item->get_product_id() );
		}
		global $wpdb;

		$table = $wpdb->prefix . 'aysquiz_quizes';
		$rows  = $wpdb->get_results( "SELECT id, options FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids   = array();
		foreach ( (array) $rows as $row ) {
			$options = json_decode( (string) $row->options, true );
			$product = is_array( $options ) && ! empty( $options['woocommerce_product'] ) ? absint( $options['woocommerce_product'] ) : 0;
			if ( $product && in_array( $product, $products, true ) ) {
				$ids[] = absint( $row->id );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function quiz_ids_for_product( $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aysquiz_quizes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows = $wpdb->get_results( "SELECT id, options FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids  = array();
		foreach ( (array) $rows as $row ) {
			$options = json_decode( (string) $row->options, true );
			if ( is_array( $options ) && absint( $options['woocommerce_product'] ?? 0 ) === absint( $product_id ) ) {
				$ids[] = absint( $row->id );
			}
		}
		return $ids;
	}

	/** Render assigned quizzes and AYS reports for the selected child. */
	public static function render_dashboard( $target_user_id, $viewer_user_id ) {
		$target_user_id = absint( $target_user_id );
		$viewer_user_id = absint( $viewer_user_id );
		if ( ! $target_user_id || ! $viewer_user_id ) {
			return '';
		}
		if ( $target_user_id !== $viewer_user_id && ( ! class_exists( 'Prep_Expert_Parent_Child_Database' ) || ! Prep_Expert_Parent_Child_Database::can_parent_access_child( $viewer_user_id, $target_user_id ) ) ) {
			return '';
		}

		$quiz_ids = get_user_meta( $target_user_id, self::ENROLLED_META, true );
		$quiz_ids = is_array( $quiz_ids ) ? array_values( array_filter( array_map( 'absint', $quiz_ids ) ) ) : array();
		$out = '<section class="prep-parent-quizzes" style="margin-top:24px;"><h2>' . esc_html__( 'Mock Exams', 'prep-expert-exam-papers' ) . '</h2>';
		if ( empty( $quiz_ids ) ) {
			return $out . '<p>' . esc_html__( 'No mock exams enrolled for this student.', 'prep-expert-exam-papers' ) . '</p></section>';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'aysquiz_quizes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out . '<p>' . esc_html__( 'Quiz service is currently unavailable.', 'prep-expert-exam-papers' ) . '</p></section>';
		}

		$requested_quiz = isset( $_GET['quiz_id'] ) ? absint( $_GET['quiz_id'] ) : 0;
		if ( $requested_quiz && in_array( $requested_quiz, $quiz_ids, true ) && shortcode_exists( 'ays_quiz' ) ) {
			$out .= '<div class="prep-parent-quiz-player" style="margin-bottom:24px;">' . do_shortcode( '[ays_quiz id="' . $requested_quiz . '"]' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$placeholders = implode( ', ', array_fill( 0, count( $quiz_ids ), '%d' ) );
		$query = $wpdb->prepare( "SELECT id, title FROM {$table} WHERE id IN ({$placeholders})", $quiz_ids );
		$quizzes = $wpdb->get_results( $query );
		$out .= '<div style="overflow-x:auto;"><table class="prep-live-class-table widefat striped" style="width:100%;border-collapse:collapse;text-align:left;min-width:650px;"><thead><tr><th style="padding:10px;">' . esc_html__( 'Exam', 'prep-expert-exam-papers' ) . '</th><th style="padding:10px;">' . esc_html__( 'Latest Result', 'prep-expert-exam-papers' ) . '</th><th style="padding:10px;">' . esc_html__( 'Action', 'prep-expert-exam-papers' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $quizzes as $quiz ) {
			$report = self::latest_report( absint( $quiz->id ), $target_user_id );
			$result = $report ? esc_html( self::report_summary( $report ) ) : esc_html__( 'Not attempted', 'prep-expert-exam-papers' );
			$link   = add_query_arg( 'quiz_id', absint( $quiz->id ), home_url( '/' ) );
			$out .= '<tr><td style="padding:10px;"><strong>' . esc_html( $quiz->title ) . '</strong></td><td style="padding:10px;">' . $result . '</td><td style="padding:10px;"><a class="mqt-btn" href="' . esc_url( $link ) . '">' . esc_html__( 'Start Exam', 'prep-expert-exam-papers' ) . '</a></td></tr>';
		}
		return $out . '</tbody></table></div></section>';
	}

	private static function latest_report( $quiz_id, $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aysquiz_reports';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT score, points, end_date FROM {$table} WHERE quiz_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1", $quiz_id, $user_id ) );
	}

	private static function report_summary( $report ) {
		$score = isset( $report->score ) && '' !== $report->score ? $report->score . '%' : '—';
		$points = isset( $report->points ) && '' !== $report->points ? ' (' . $report->points . ' points)' : '';
		return $score . $points;
	}
}

add_action( 'plugins_loaded', array( 'Prep_Expert_Quiz_Parent_Extension', 'init' ) );
