<?php
/**
 * Live Classes engine: WooCommerce enrollment, booking, waiting list,
 * student dashboard, secure Zoom redirects, attendance, and admin reports.
 *
 * @package PrepExpertExamPapers
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Prep_Expert_Live_Class {
	const POST_TYPE = 'live-class';
	const ENROLLED_USER_META = '_enrolled_live_class';
	const ENROLLED_POST_META = '_enrolled_student_id';
	const ATTENDANCE_POST_META = '_attendance_record';
	const WAITLIST_META = '_live_class_waitlist';

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enrol_order_user' ) );
		add_shortcode( 'live_class_booking', array( __CLASS__, 'booking_shortcode' ) );
		add_shortcode( 'live_classes', array( __CLASS__, 'classes_shortcode' ) );
		add_shortcode( 'student_live_classes', array( __CLASS__, 'dashboard_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'handle_waitlist_post' ), 1 );
		add_action( 'admin_post_prep_expert_join_live_class', array( __CLASS__, 'join_class' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	private static function field( $post_id, $key, $default = '' ) {
		if ( ! function_exists( 'get_field' ) ) { return $default; }
		$value = get_field( $key, $post_id );
		if ( is_object( $value ) && isset( $value->ID ) ) { $value = $value->ID; }
		return '' !== $value && null !== $value ? $value : $default;
	}

	private static function ids( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	private static function class_ids_for_product( $product_id ) {
		$posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'linked_wc_product', 'meta_value' => absint( $product_id ) ) );
		return array_map( 'absint', $posts );
	}

	private static function user_has_access( $class_id, $user_id ) {
		if ( in_array( absint( $class_id ), self::ids( get_user_meta( $user_id, self::ENROLLED_USER_META, true ) ), true ) ) { return true; }
		$product_id = absint( self::field( $class_id, 'linked_wc_product', 0 ) );
		$user = get_userdata( $user_id );
		return $product_id && $user && function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $user->user_email, $user_id, $product_id );
	}

	public static function enrol_order_user( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! $order->get_user_id() ) { return; }
		$user_id = absint( $order->get_user_id() );
		$user_classes = self::ids( get_user_meta( $user_id, self::ENROLLED_USER_META, true ) );
		foreach ( $order->get_items() as $item ) {
			foreach ( self::class_ids_for_product( $item->get_product_id() ) as $class_id ) {
				$enrolled = self::ids( get_post_meta( $class_id, self::ENROLLED_POST_META, true ) );
				if ( ! in_array( $user_id, $enrolled, true ) ) { $enrolled[] = $user_id; update_post_meta( $class_id, self::ENROLLED_POST_META, $enrolled ); }
				if ( ! in_array( $class_id, $user_classes, true ) ) { $user_classes[] = $class_id; }
			}
		}
		update_user_meta( $user_id, self::ENROLLED_USER_META, $user_classes );
	}

	public static function handle_waitlist_post() {
		if ( empty( $_POST['prep_expert_waitlist'] ) ) { return; }
		$class_id = absint( wp_unslash( $_POST['live_class_id'] ?? 0 ) );
		$email = sanitize_email( wp_unslash( $_POST['waitlist_email'] ?? '' ) );
		if ( ! $class_id || self::POST_TYPE !== get_post_type( $class_id ) || ! is_email( $email ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'prep_expert_waitlist_' . $class_id ) ) { return; }
		$emails = get_post_meta( $class_id, self::WAITLIST_META, true );
		$emails = is_array( $emails ) ? array_map( 'sanitize_email', $emails ) : array();
		if ( ! in_array( $email, $emails, true ) ) { $emails[] = $email; update_post_meta( $class_id, self::WAITLIST_META, $emails ); }
		wp_safe_redirect( add_query_arg( 'live_class_waitlist', 'success', wp_get_referer() ?: get_permalink( $class_id ) ) ); exit;
	}

	public static function booking_shortcode( $atts ) {
		$class_id = absint( shortcode_atts( array( 'id' => 0 ), $atts )['id'] );
		if ( ! $class_id || self::POST_TYPE !== get_post_type( $class_id ) ) { return ''; }
		$capacity = max( 0, (int) self::field( $class_id, 'class_capacity', 0 ) );
		$enrolled = self::ids( get_post_meta( $class_id, self::ENROLLED_POST_META, true ) );
		$remaining = max( 0, $capacity - count( $enrolled ) );
		$product_id = absint( self::field( $class_id, 'linked_wc_product', 0 ) );
		$start = self::class_time( $class_id );
		$out = '<div class="prep-expert-live-class-booking"><p><strong>' . esc_html__( 'Date:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $start ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '—' ) . '<br><strong>' . esc_html__( 'Duration:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( self::field( $class_id, 'class_duration', '—' ) ) . '<br><strong>' . esc_html__( 'Capacity:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $capacity ) . '<br>' . esc_html( sprintf( __( '%d seats remaining.', 'prep-expert-exam-papers' ), $remaining ) ) . '</p>';
		if ( $remaining > 0 && $product_id && function_exists( 'wc_get_checkout_url' ) ) {
			$out .= '<a class="button" href="' . esc_url( add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ) ) . '">' . esc_html__( 'Book & Pay Now', 'prep-expert-exam-papers' ) . '</a>';
		} else {
			$out .= '<form method="post"><input type="email" name="waitlist_email" required placeholder="' . esc_attr__( 'Your email address', 'prep-expert-exam-papers' ) . '"><input type="hidden" name="live_class_id" value="' . esc_attr( $class_id ) . '"><input type="hidden" name="prep_expert_waitlist" value="1">' . wp_nonce_field( 'prep_expert_waitlist_' . $class_id, '_wpnonce', true, false ) . '<button type="submit">' . esc_html__( 'Join Waiting List', 'prep-expert-exam-papers' ) . '</button></form>';
		}
		return $out . '</div>';
	}

	/**
	 * Render published live classes as a frontend list.
	 * Usage: [live_classes] or [live_classes limit="12" order="ASC"]
	 */
	public static function classes_shortcode( $atts ) {
		$css_path = plugin_dir_path( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
		$css_url  = plugin_dir_url( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
		wp_enqueue_style( 'prep-expert-live-classes', $css_url, array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0' );
		$atts = shortcode_atts( array( 'limit' => -1, 'order' => 'ASC' ), $atts, 'live_classes' );
		$limit = (int) $atts['limit'];
		$order = 'DESC' === strtoupper( sanitize_key( $atts['order'] ) ) ? 'DESC' : 'ASC';
		$classes = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => $limit > 0 ? $limit : -1, 'orderby' => 'date', 'order' => $order ) );
		if ( ! $classes ) { return '<p class="prep-expert-live-classes-empty">' . esc_html__( 'No live classes are currently available.', 'prep-expert-exam-papers' ) . '</p>'; }

		$out = '<div class="prep-expert-live-classes-list">';
		foreach ( $classes as $class ) {
			$class_id  = absint( $class->ID );
			$capacity  = max( 0, (int) self::field( $class_id, 'class_capacity', 0 ) );
			$occupied  = count( self::ids( get_post_meta( $class_id, self::ENROLLED_POST_META, true ) ) );
			$start     = self::class_time( $class_id );
			$product_id = absint( self::field( $class_id, 'linked_wc_product', 0 ) );
			$available = $capacity > $occupied && $product_id && function_exists( 'wc_get_checkout_url' );
			
			$out .= '<article class="prep-expert-live-class-item">';
			if ( has_post_thumbnail( $class_id ) ) { $out .= '<div class="prep-expert-live-class-image"><a href="' . esc_url( get_permalink( $class_id ) ) . '">' . get_the_post_thumbnail( $class_id, 'medium', array( 'loading' => 'lazy' ) ) . '</a></div>'; }
			$out .= '<div class="prep-expert-live-class-content"><h3 class="prep-expert-live-class-title">' . esc_html( get_the_title( $class_id ) ) . '</h3>';
			$out .= '<p class="prep-expert-live-class-details"><span class="prep-expert-live-class-duration"><strong>' . esc_html__( 'Duration:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( self::field( $class_id, 'class_duration', '—' ) ) . '</span> <span class="prep-expert-live-class-date"><strong>' . esc_html__( 'Date/Time:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $start ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '—' ) . '</span> <span class="prep-expert-live-class-capacity"><strong>' . esc_html__( 'Capacity:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $occupied . ' / ' . $capacity ) . '</span></p>';
			if ( $available ) {
				$out .= '<a class="button prep-expert-live-class-book" href="' . esc_url( add_query_arg( 'add-to-cart', $product_id, wc_get_checkout_url() ) ) . '">' . esc_html__( 'Book Seat', 'prep-expert-exam-papers' ) . '</a>';
			} else {
				$out .= '<span class="prep-expert-live-class-sold-out">' . esc_html( $capacity <= $occupied ? __( 'Fully booked', 'prep-expert-exam-papers' ) : __( 'Booking unavailable', 'prep-expert-exam-papers' ) ) . '</span>';
			}
			$out .= '</div></article>';
		}
		return $out . '</div>';
	}

	private static function class_time( $class_id ) { $raw = self::field( $class_id, 'class_date_time' ); return $raw ? strtotime( $raw ) : false; }

	public static function dashboard_shortcode() {
		if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Please log in to view your classes.', 'prep-expert-exam-papers' ) . '</p>'; }
		$user_id = get_current_user_id(); $classes = self::ids( get_user_meta( $user_id, self::ENROLLED_USER_META, true ) );
		// Include completed purchases immediately, even if the enrollment hook has not run yet.
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $candidate_id ) {
			if ( self::user_has_access( $candidate_id, $user_id ) && ! in_array( absint( $candidate_id ), $classes, true ) ) { $classes[] = absint( $candidate_id ); }
		}
		$out = '<table class="prep-expert-live-classes"><thead><tr><th>Class</th><th>Date/Time</th><th>Duration</th><th>Capacity</th><th>Status</th><th>Recording</th></tr></thead><tbody>';
		foreach ( $classes as $class_id ) { if ( self::POST_TYPE !== get_post_type( $class_id ) || ! self::user_has_access( $class_id, $user_id ) ) { continue; } $start = self::class_time( $class_id ); $record = get_post_meta( $class_id, self::ATTENDANCE_POST_META, true ); $record = is_array( $record ) ? $record : array(); $attended = isset( $record[ $user_id ] ); $now = current_time( 'timestamp' ); $status = $attended ? '✓ Attended' : ( $start && $now >= $start - 900 && $now <= $start + 7200 ? '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=prep_expert_join_live_class&class_id=' . $class_id ), 'prep_expert_join_' . $class_id ) ) . '">Join Class</a>' : ( $start && $now < $start ? 'Upcoming' : 'Ended' ) ); $recording = self::field( $class_id, 'class_recording_url' ); $out .= '<tr><td>' . esc_html( get_the_title( $class_id ) ) . '</td><td>' . esc_html( $start ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '—' ) . '</td><td>' . esc_html( self::field( $class_id, 'class_duration', '—' ) ) . '</td><td>' . esc_html( self::field( $class_id, 'class_capacity', '—' ) ) . '</td><td>' . wp_kses_post( $status ) . '</td><td>' . ( $recording && wp_http_validate_url( $recording ) ? '<a href="' . esc_url( $recording ) . '" target="_blank" rel="noopener">View Recording</a>' : '—' ) . '</td></tr>'; }
		return $out . '</tbody></table>';
	}

	public static function join_class() {
		$class_id = absint( $_GET['class_id'] ?? 0 ); if ( ! is_user_logged_in() || ! $class_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prep_expert_join_' . $class_id ) ) { wp_die( esc_html__( 'Invalid join request.', 'prep-expert-exam-papers' ), 403 ); }
		$user_id = get_current_user_id(); if ( ! self::user_has_access( $class_id, $user_id ) ) { wp_die( esc_html__( 'You must purchase this class before joining.', 'prep-expert-exam-papers' ), 403 ); }
		$start = self::class_time( $class_id ); $now = current_time( 'timestamp' ); if ( ! $start || $now < $start - 900 || $now > $start + 7200 ) { wp_die( esc_html__( 'This class is not currently available.', 'prep-expert-exam-papers' ), 403 ); }
		$timestamp = current_time( 'mysql' ); update_user_meta( $user_id, '_attended_class_' . $class_id, $timestamp ); $record = get_post_meta( $class_id, self::ATTENDANCE_POST_META, true ); $record = is_array( $record ) ? $record : array(); $record[ $user_id ] = $timestamp; update_post_meta( $class_id, self::ATTENDANCE_POST_META, $record );
		$meeting_id = preg_replace( '/[^0-9]/', '', (string) self::field( $class_id, 'zoom_meeting_id' ) ); $url = $meeting_id ? 'https://zoom.us/j/' . $meeting_id : ''; if ( ! $url || ! wp_http_validate_url( $url ) ) { wp_die( esc_html__( 'The class link is unavailable.', 'prep-expert-exam-papers' ), 500 ); } wp_safe_redirect( $url ); exit;
	}


	public static function admin_menu() { add_submenu_page( 'edit.php?post_type=' . self::POST_TYPE, 'Attendance Reports', 'Attendance Reports', 'manage_options', 'prep-expert-live-attendance', array( __CLASS__, 'attendance_page' ) ); }

	public static function attendance_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; } echo '<div class="wrap"><h1>' . esc_html__( 'Live Class Attendance Reports', 'prep-expert-exam-papers' ) . '</h1><table class="widefat striped"><thead><tr><th>Class</th><th>Enrolled student</th><th>Attendance</th><th>Join timestamp</th></tr></thead><tbody>';
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $class ) { $enrolled = self::ids( get_post_meta( $class->ID, self::ENROLLED_POST_META, true ) ); $records = get_post_meta( $class->ID, self::ATTENDANCE_POST_META, true ); $records = is_array( $records ) ? $records : array(); foreach ( $enrolled as $user_id ) { $user = get_userdata( $user_id ); echo '<tr><td>' . esc_html( get_the_title( $class ) ) . '</td><td>' . esc_html( $user ? $user->user_email : 'User #' . $user_id ) . '</td><td>' . esc_html( isset( $records[ $user_id ] ) ? 'Attended' : 'Not attended' ) . '</td><td>' . esc_html( isset( $records[ $user_id ] ) ? $records[ $user_id ] : '—' ) . '</td></tr>'; } }
		echo '</tbody></table></div>';
	}
}

Prep_Expert_Live_Class::init();
