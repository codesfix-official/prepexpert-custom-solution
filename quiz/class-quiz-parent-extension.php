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
	const QUIZ_PAGE_OPTION = 'prep_expert_child_quiz_page_id';
	const QUIZ_PAGE_SLUG = 'quiz';

	public static function init() {
		add_action(
			'woocommerce_order_status_processing',
			array( __CLASS__, 'enrol_child_quizzes' ),
			30
		);

		add_action(
			'woocommerce_order_status_completed',
			array( __CLASS__, 'enrol_child_quizzes' ),
			30
		);

		add_action(
			'woocommerce_payment_complete',
			array( __CLASS__, 'enrol_child_quizzes' ),
			30
		);

		add_filter(
			'woocommerce_customer_bought_product',
			array( __CLASS__, 'allow_child_quiz_product_access' ),
			20,
			4
		);

		add_filter(
			'ays_qm_woocommerce_front_end_integrations',
			array( __CLASS__, 'allow_assigned_child_quiz' ),
			9999,
			1
		);

		add_action(
			'init',
			array( __CLASS__, 'ensure_quiz_page' ),
			5
		);

		add_filter(
			'the_content',
			array( __CLASS__, 'render_quiz_root_route' ),
			30
		);
	}

	/** Ensure one stable WordPress page provides normal Quiz Maker page context. */
	public static function ensure_quiz_page() {
		$page_id = absint( get_option( self::QUIZ_PAGE_OPTION ) );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return;
		}

		$page = get_page_by_path( self::QUIZ_PAGE_SLUG, OBJECT, 'page' );
		if ( $page instanceof WP_Post ) {
			update_option( self::QUIZ_PAGE_OPTION, $page->ID, false );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Child Quiz', 'prep-expert-exam-papers' ),
				'post_name'    => self::QUIZ_PAGE_SLUG,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);
		if ( ! is_wp_error( $page_id ) ) {
			update_option( self::QUIZ_PAGE_OPTION, absint( $page_id ), false );
		}
	}

/**
 * Render a quiz opened from a child dashboard Start Exam link.
 *
 * @param string $content Current page content.
 * @return string
 */
public static function render_quiz_root_route( $content ) {

	if (
		is_admin() ||
		! is_page( self::QUIZ_PAGE_SLUG ) ||
		! is_user_logged_in() ||
		! isset( $_GET['quiz_id'] ) ||
		! shortcode_exists( 'ays_quiz' )
	) {
		return $content;
	}

	$quiz_id = absint(
		wp_unslash( $_GET['quiz_id'] )
	);

	if ( ! $quiz_id ) {
		return $content;
	}

	$current_user_id = get_current_user_id();

	/*
	 * Synchronize any newly paid direct or parent-assigned orders.
	 */
	self::sync_child_quizzes_from_orders(
		$current_user_id
	);

	/*
	 * Perform the real authorization check.
	 */
	if (
		! self::user_has_authorized_quiz_access(
			$current_user_id,
			$quiz_id
		)
	) {
		return $content;
	}

	/*
	 * AYS WooCommerce integration will execute while this shortcode
	 * is rendered. Our 9999 filter will remove its purchase block
	 * for this authorized child + quiz.
	 */
	$player = do_shortcode(
		'[ays_quiz id="' . $quiz_id . '"]'
	);

	if ( '' === trim( $player ) ) {
		return $content;
	}

	return $content .
		'<div class="prep-parent-quiz-player">' .
		$player .
		'</div>';
}

	/**
	 * Check whether the quiz is enrolled for the user.
	 *
	 * This is an enrollment/meta check only.
	 * Actual WooCommerce authorization is handled by
	 * user_has_authorized_quiz_access().
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return bool
	 */
	private static function user_has_quiz_access( $user_id, $quiz_id ) {

		$assigned = get_user_meta(
			absint( $user_id ),
			self::ENROLLED_META,
			true
		);

		$assigned = is_array( $assigned )
			? array_map( 'absint', $assigned )
			: array();

		return in_array(
			absint( $quiz_id ),
			$assigned,
			true
		);
	}

	/** Recover assignments for older paid orders when a child opens the dashboard. */
/**
 * Synchronize quiz enrollment from both direct student orders
 * and parent -> child orders.
 *
 * @param int $child_id Child/student user ID.
 * @return void
 */
private static function sync_child_quizzes_from_orders( $child_id ) {

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}

	$child_id = absint( $child_id );

	if ( ! $child_id ) {
		return;
	}

	/*
	 * ---------------------------------------------------------
	 * 1. Direct child/student purchases.
	 * ---------------------------------------------------------
	 */
	$child_orders = wc_get_orders(
		array(
			'customer_id' => $child_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => 100,
			'return'      => 'objects',
		)
	);

	foreach ( (array) $child_orders as $order ) {
		self::enrol_child_quizzes( $order->get_id() );
	}

	/*
	 * ---------------------------------------------------------
	 * 2. Parent -> child purchases.
	 * ---------------------------------------------------------
	 */
	if (
		! class_exists( 'Prep_Expert_Parent_Child_Database' )
	) {
		return;
	}

	$parent_id =
		Prep_Expert_Parent_Child_Database::get_parent_by_child(
			$child_id
		);

	if ( ! $parent_id ) {
		return;
	}

	$parent_orders = wc_get_orders(
		array(
			'customer_id' => $parent_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => 100,
			'return'      => 'objects',
		)
	);

	foreach ( (array) $parent_orders as $order ) {

		if (
			absint(
				$order->get_meta( '_enrolled_child_user_id' )
			) !== $child_id
		) {
			continue;
		}

		self::enrol_child_quizzes( $order->get_id() );
	}
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
			if ( self::user_has_quiz_access( $user_id, $quiz_id ) ) {
				return true;
			}
		}
		return $bought;
	}

/**
 * Bypass the AYS WooCommerce purchase block for a child who is
 * legitimately authorized to take the current quiz.
 *
 * AYS WooCommerce addon normally checks the logged-in user's own
 * WooCommerce orders. For parent -> child purchases, the order belongs
 * to the parent, so AYS incorrectly displays the purchase requirement.
 *
 * This filter runs after the AYS WooCommerce integration and removes
 * only the WooCommerce block when the current child has valid access.
 *
 * @param mixed $integration AYS WooCommerce integration result.
 * @return mixed
 */
		public static function allow_assigned_child_quiz( $integration ) {

			if (
				! is_user_logged_in() ||
				! class_exists( 'Prep_Expert_Parent_Child_Database' )
			) {
				return $integration;
			}

			$user_id = get_current_user_id();

			/*
			* Our custom quiz route uses ?quiz_id=123.
			* This lets us identify exactly which quiz is being rendered.
			*/
			$quiz_id = isset( $_GET['quiz_id'] )
				? absint( wp_unslash( $_GET['quiz_id'] ) )
				: 0;

			if ( ! $user_id || ! $quiz_id ) {
				return $integration;
			}

			/*
			* Only bypass AYS when this exact child has legitimate access
			* to this exact quiz.
			*/
			if ( ! self::user_has_authorized_quiz_access( $user_id, $quiz_id ) ) {
				return $integration;
			}

			/*
			* AYS treats an empty WooCommerce integration result as:
			* "there is no WooCommerce restriction to display".
			*
			* This suppresses the Buy/Add-to-cart block only for this
			* authorized child + quiz combination.
			*/
			return array();
		}

		/**
 * Verify that a user is genuinely authorized to take a specific quiz.
 *
 * Supported cases:
 *
 * 1. Parent purchased the quiz for this child.
 * 2. Child purchased the quiz directly.
 *
 * The check is intentionally tied to the exact quiz/product/order.
 *
 * @param int $user_id Current logged-in user.
 * @param int $quiz_id Quiz ID.
 * @return bool
 */
private static function user_has_authorized_quiz_access( $user_id, $quiz_id ) {

	$user_id = absint( $user_id );
	$quiz_id = absint( $quiz_id );

	if (
		! $user_id ||
		! $quiz_id ||
		! function_exists( 'wc_get_orders' )
	) {
		return false;
	}

	/*
	 * The quiz must actually be assigned/enrolled to this user.
	 */
	$assigned = get_user_meta(
		$user_id,
		self::ENROLLED_META,
		true
	);

	$assigned = is_array( $assigned )
		? array_map( 'absint', $assigned )
		: array();

	if ( ! in_array( $quiz_id, $assigned, true ) ) {
		return false;
	}

	/*
	 * Find the WooCommerce product linked to this exact quiz.
	 */
	$product_id = self::linked_product_for_quiz( $quiz_id );

	if ( ! $product_id ) {
		return false;
	}

	/*
	 * ---------------------------------------------------------
	 * CASE 1: Child purchased the quiz directly.
	 * ---------------------------------------------------------
	 *
	 * The WooCommerce order belongs directly to the child.
	 */
	$child_orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => 100,
			'return'      => 'objects',
		)
	);

	foreach ( (array) $child_orders as $order ) {

		foreach ( $order->get_items() as $item ) {

			if (
				absint( $item->get_product_id() ) === $product_id
			) {
				return true;
			}
		}
	}

	/*
	 * ---------------------------------------------------------
	 * CASE 2: Parent purchased the quiz for this child.
	 * ---------------------------------------------------------
	 */
	$parent_id = Prep_Expert_Parent_Child_Database::get_parent_by_child(
		$user_id
	);

	if ( ! $parent_id ) {
		return false;
	}

	/*
	 * Confirm the parent/child relationship.
	 */
	if (
		! Prep_Expert_Parent_Child_Database::can_parent_access_child(
			$parent_id,
			$user_id
		)
	) {
		return false;
	}

	$parent_orders = wc_get_orders(
		array(
			'customer_id' => $parent_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => 100,
			'return'      => 'objects',
		)
	);

	foreach ( (array) $parent_orders as $order ) {

		/*
		 * The order must explicitly belong to this child.
		 */
		if (
			absint(
				$order->get_meta( '_enrolled_child_user_id' )
			) !== $user_id
		) {
			continue;
		}

		/*
		 * The order must contain the exact WooCommerce product
		 * linked to this quiz.
		 */
		foreach ( $order->get_items() as $item ) {

			if (
				absint( $item->get_product_id() ) === $product_id
			) {
				return true;
			}
		}
	}

	return false;
}

	/** Get the WooCommerce product linked to one Quiz Maker quiz. */
	private static function linked_product_for_quiz( $quiz_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aysquiz_quizes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		$options = $wpdb->get_var( $wpdb->prepare( "SELECT options FROM {$table} WHERE id = %d", absint( $quiz_id ) ) );
		$options = json_decode( (string) $options, true );
		return is_array( $options ) ? absint( $options['woocommerce_product'] ?? 0 ) : 0;
	}

			/**
		 * Enroll quizzes from a paid WooCommerce order.
		 *
		 * Supports:
		 *
		 * 1. Parent -> child purchases:
		 *    _enrolled_child_user_id identifies the child.
		 *
		 * 2. Direct child purchases:
		 *    When _enrolled_child_user_id is absent, the order customer
		 *    is treated as the student.
		 *
		 * @param int $order_id WooCommerce order ID.
		 * @return void
		 */
		public static function enrol_child_quizzes( $order_id ) {

			if ( ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			$order = wc_get_order( absint( $order_id ) );

			if ( ! $order ) {
				return;
			}

			$customer_id = absint( $order->get_customer_id() );

			if ( ! $customer_id ) {
				return;
			}

			$assigned_child_id = absint(
				$order->get_meta( '_enrolled_child_user_id' )
			);

			/*
			* ---------------------------------------------------------
			* CASE 1: Parent purchased for a child.
			* ---------------------------------------------------------
			*/
			if ( $assigned_child_id ) {

				if (
					$assigned_child_id === $customer_id ||
					! class_exists( 'Prep_Expert_Parent_Child_Database' )
				) {
					return;
				}

				if (
					! Prep_Expert_Parent_Child_Database::can_parent_access_child(
						$customer_id,
						$assigned_child_id
					)
				) {
					return;
				}

				$quiz_ids = self::quiz_ids_for_order( $order );

				if ( empty( $quiz_ids ) ) {
					return;
				}

				$assigned = get_user_meta(
					$assigned_child_id,
					self::ENROLLED_META,
					true
				);

				$assigned = is_array( $assigned )
					? array_map( 'absint', $assigned )
					: array();

				update_user_meta(
					$assigned_child_id,
					self::ENROLLED_META,
					array_values(
						array_unique(
							array_merge( $assigned, $quiz_ids )
						)
					)
				);

				return;
			}

			/*
			* ---------------------------------------------------------
			* CASE 2: Child/student purchased directly.
			* ---------------------------------------------------------
			*
			* No _enrolled_child_user_id means the order belongs directly
			* to the student.
			*/
			$quiz_ids = self::quiz_ids_for_order( $order );

			if ( empty( $quiz_ids ) ) {
				return;
			}

			$assigned = get_user_meta(
				$customer_id,
				self::ENROLLED_META,
				true
			);

			$assigned = is_array( $assigned )
				? array_map( 'absint', $assigned )
				: array();

			update_user_meta(
				$customer_id,
				self::ENROLLED_META,
				array_values(
					array_unique(
						array_merge( $assigned, $quiz_ids )
					)
				)
			);
		}

	private static function quiz_ids_for_order( $order ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = absint( $item->get_product_id() );
		}
		global $wpdb;

		$table = $wpdb->prefix . 'aysquiz_quizes';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
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
		self::sync_child_quizzes_from_orders( $target_user_id );

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
			$quiz_page_id = absint( get_option( self::QUIZ_PAGE_OPTION ) );
			$quiz_page_url = $quiz_page_id ? get_permalink( $quiz_page_id ) : home_url( '/' . self::QUIZ_PAGE_SLUG . '/' );
			$link   = add_query_arg( 'quiz_id', absint( $quiz->id ), $quiz_page_url );
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
