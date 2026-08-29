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
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'enrol_order_user' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'enrol_order_user' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enrol_order_user' ) );
		add_shortcode( 'live_class_booking', array( __CLASS__, 'booking_shortcode' ) );
		add_shortcode( 'live_classes', array( __CLASS__, 'classes_shortcode' ) );
		add_shortcode( 'student_live_classes', array( __CLASS__, 'dashboard_shortcode' ) );
		add_filter( 'stm_lms_menu_items', array( __CLASS__, 'add_account_menu_item' ) );
		add_filter( 'stm_lms_sorted_menu', array( __CLASS__, 'add_account_menu_item' ) );
		add_filter( 'stm_lms_sorted_student_menu', array( __CLASS__, 'add_account_menu_item' ) );
		add_action( 'init', array( __CLASS__, 'handle_waitlist_post' ), 1 );
		
		// Admin-post URL redirection fix
		add_action( 'init', array( __CLASS__, 'handle_frontend_join_request' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function field( $post_id, $key, $default = '' ) {
		if ( ! function_exists( 'get_field' ) ) { return $default; }
		$value = get_field( $key, $post_id );
		if ( is_object( $value ) && isset( $value->ID ) ) { $value = $value->ID; }
		return '' !== $value && null !== $value ? $value : $default;
	}

	public static function ids( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	public static function class_ids_for_product( $product_id ) {
		$posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'linked_wc_product', 'meta_value' => absint( $product_id ) ) );
		return array_map( 'absint', $posts );
	}

	public static function user_has_access( $class_id, $user_id ) {
		$user_id  = absint( $user_id );
		$class_id = absint( $class_id );
		if ( ! $user_id || ! $class_id ) {
			return false;
		}
		if ( self::POST_TYPE !== get_post_type( $class_id ) || 'publish' !== get_post_status( $class_id ) ) {
			return false;
		}

		// 1. Check direct user meta arrays (Loose comparison array match)
		$enrolled_classes = array_map( 'absint', (array) self::ids( get_user_meta( $user_id, self::ENROLLED_USER_META, true ) ) );
		$alt_classes      = array_map( 'absint', (array) self::ids( get_user_meta( $user_id, '_enrolled_live_classes', true ) ) );
		$all_user_classes = array_unique( array_merge( $enrolled_classes, $alt_classes ) );

		if ( in_array( $class_id, $all_user_classes, true ) ) {
			return true;
		}

		// 2. Individual enrolled meta key check
		if ( get_user_meta( $user_id, '_enrolled_live_class_' . $class_id, true ) ) {
			return true;
		}

		// 3. Class post meta enrolled students array check
		$enrolled_students = array_map( 'absint', (array) self::ids( get_post_meta( $class_id, self::ENROLLED_POST_META, true ) ) );
		if ( in_array( $user_id, $enrolled_students, true ) ) {
			return true;
		}

		// 4. Course-Level Access Mapping Check (Parent Product ID Check)
		$associated_course = absint( get_post_meta( $class_id, '_associated_course_id', true ) );
		if ( $associated_course ) {
			$enrolled_courses = array_map( 'absint', (array) get_user_meta( $user_id, '_enrolled_course_ids', true ) );
			if ( in_array( $associated_course, $enrolled_courses, true ) ) {
				return true;
			}
		}

		// 5. Parent Account Fallback (If user is Child, check Parent Enrolments)
		if ( class_exists( 'Prep_Expert_Parent_Child_Database' ) ) {
			$parent_id = Prep_Expert_Parent_Child_Database::get_parent_by_child( $user_id );
			if ( $parent_id && $parent_id !== $user_id ) {
				// Recursive check for parent access linked to child
				$parent_courses = array_map( 'absint', (array) get_user_meta( $parent_id, '_enrolled_course_ids', true ) );
				if ( $associated_course && in_array( $associated_course, $parent_courses, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function add_account_menu_item( $menus ) {
		if ( ! is_user_logged_in() || ! is_array( $menus ) ) { return $menus; }
		foreach ( $menus as $menu ) {
			if ( is_array( $menu ) && ( 'prep-live-classes' === ( $menu['id'] ?? '' ) || 'prep-live-classes' === ( $menu['slug'] ?? '' ) ) ) { return $menus; }
		}
		$page = get_page_by_path( 'student-live-classes' );
		$url  = $page ? get_permalink( $page ) : ( function_exists( 'ms_plugin_user_account_url' ) ? ms_plugin_user_account_url( 'prep-live-classes' ) : home_url( '/student-live-classes/' ) );
		$menus[] = array( 'order' => 175, 'id' => 'prep-live-classes', 'slug' => 'prep-live-classes', 'lms_template' => 'account/main', 'menu_title' => esc_html__( 'My Live Classes', 'prep-expert-exam-papers' ), 'menu_icon' => 'stmlms-menu-live-stream', 'menu_url' => $url, 'is_active' => is_page( 'student-live-classes' ), 'menu_place' => 'learning', 'section' => 'account' );
		return $menus;
	}

	public static function enrol_order_user( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) { return; }

		// Idempotency check
		if ( '1' === $order->get_meta( '_prep_expert_enrolment_processed' ) ) {
			return;
		}

		$selected_child = $order->get_meta( '_enrolled_child_user_id' );
		$target_user_id = $selected_child ? absint( $selected_child ) : absint( $order->get_user_id() );

		if ( ! $target_user_id ) {
			$target_user_id = absint( $order->get_customer_id() );
		}

		if ( ! $target_user_id ) {
			return;
		}

		$parent_user_id     = absint( $order->get_user_id() );
		$is_child_enrollment = ( $selected_child && $target_user_id !== $parent_user_id );

		$user_classes   = self::ids( get_user_meta( $target_user_id, self::ENROLLED_USER_META, true ) );
		$alt_classes    = self::ids( get_user_meta( $target_user_id, '_enrolled_live_classes', true ) );
		$user_classes   = array_values( array_unique( array_merge( $user_classes, $alt_classes ) ) );

		$parent_classes = array();
		if ( $is_child_enrollment && $parent_user_id ) {
			$p_classes      = self::ids( get_user_meta( $parent_user_id, self::ENROLLED_USER_META, true ) );
			$p_alt_classes  = self::ids( get_user_meta( $parent_user_id, '_enrolled_live_classes', true ) );
			$parent_classes = array_values( array_unique( array_merge( $p_classes, $p_alt_classes ) ) );
		}

		$processed = false;

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$class_ids  = self::class_ids_for_product( $product_id );

			$meta_class_ids = get_post_meta( $product_id, '_linked_live_class_ids', true );
			if ( is_array( $meta_class_ids ) ) {
				$class_ids = array_unique( array_merge( $class_ids, array_map( 'absint', $meta_class_ids ) ) );
			}

			foreach ( $class_ids as $class_id ) {
				$class_id = absint( $class_id );
				if ( ! $class_id ) {
					continue;
				}

				// Enroll target student into post meta
				$enrolled = self::ids( get_post_meta( $class_id, self::ENROLLED_POST_META, true ) );
				if ( ! in_array( $target_user_id, $enrolled, true ) ) {
					$enrolled[] = $target_user_id;
				}

				// If purchased for a child, remove parent from enrolled students if present
				if ( $is_child_enrollment && $parent_user_id ) {
					$enrolled = array_values( array_diff( $enrolled, array( $parent_user_id ) ) );
				}
				update_post_meta( $class_id, self::ENROLLED_POST_META, $enrolled );

				// Enroll target student into user meta
				if ( ! in_array( $class_id, $user_classes, true ) ) {
					$user_classes[] = $class_id;
				}
				update_user_meta( $target_user_id, '_enrolled_live_class_' . $class_id, current_time( 'mysql' ) );

				// If purchased for a child, remove class from parent's personal user meta
				if ( $is_child_enrollment && $parent_user_id ) {
					$parent_classes = array_values( array_diff( $parent_classes, array( $class_id ) ) );
					delete_user_meta( $parent_user_id, '_enrolled_live_class_' . $class_id );
				}

				$processed = true;
			}
		}

		if ( $processed ) {
			update_user_meta( $target_user_id, self::ENROLLED_USER_META, $user_classes );
			update_user_meta( $target_user_id, '_enrolled_live_classes', $user_classes );

			if ( $is_child_enrollment && $parent_user_id ) {
				update_user_meta( $parent_user_id, self::ENROLLED_USER_META, $parent_classes );
				update_user_meta( $parent_user_id, '_enrolled_live_classes', $parent_classes );
			}

			$order->update_meta_data( '_prep_expert_enrolment_processed', '1' );
			$order->save();
		}
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
		$capacity = max( 0, (int) self::field( $class_id, 'class_capacity', 10 ) );
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

	public static function classes_shortcode( $atts ) {
		if ( defined( 'PREP_EXPERT_EXAM_PAPERS_FILE' ) ) {
			$css_path = plugin_dir_path( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
			$css_url  = plugin_dir_url( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
			wp_enqueue_style( 'prep-expert-live-classes', $css_url, array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0' );
		}
		$atts = shortcode_atts( array( 'limit' => -1, 'order' => 'ASC' ), $atts, 'live_classes' );
		$limit = (int) $atts['limit'];
		$order = 'DESC' === strtoupper( sanitize_key( $atts['order'] ) ) ? 'DESC' : 'ASC';
		$classes = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => $limit > 0 ? $limit : -1, 'orderby' => 'date', 'order' => $order ) );
		if ( ! $classes ) { return '<p class="prep-expert-live-classes-empty">' . esc_html__( 'No live classes are currently available.', 'prep-expert-exam-papers' ) . '</p>'; }

		$out = '<div class="prep-expert-live-classes-list">';
		foreach ( $classes as $class ) {
			$class_id  = absint( $class->ID );
			$capacity  = max( 0, (int) self::field( $class_id, 'class_capacity', 10 ) );
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

	public static function class_time( $class_id ) {
		$raw = self::field( $class_id, 'class_date_time' );
		if ( $raw instanceof DateTimeInterface ) { return $raw->getTimestamp(); }
		if ( is_numeric( $raw ) ) { return absint( $raw ); }
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return false; }
		$raw = trim( $raw );
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'd/m/Y g:i a', 'd/m/Y H:i:s', 'd/m/Y H:i', 'm/d/Y g:i a', 'm/d/Y H:i:s', 'm/d/Y H:i' ) as $format ) {
			$date = DateTime::createFromFormat( $format, $raw, wp_timezone() );
			$errors = DateTime::getLastErrors();
			if ( $date instanceof DateTime && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) { return $date->getTimestamp(); }
		}

		// ACF can return a formatted value, while the database stores another
		// format. Parse the raw ACF value as a final fallback.
		if ( function_exists( 'get_field' ) ) {
			$stored = get_field( 'class_date_time', $class_id, false );
			if ( is_string( $stored ) && $stored !== $raw ) {
				$timestamp = strtotime( $stored );
				if ( false !== $timestamp ) { return $timestamp; }
			}
		}

		$timestamp = strtotime( $raw );
		return false !== $timestamp ? $timestamp : false;
	}

	public static function dashboard_shortcode() {
		if ( class_exists( 'Prep_Expert_Live_Class_Parent_Extension' ) ) {
			return Prep_Expert_Live_Class_Parent_Extension::render_shortcode_dashboard();
		}

		if ( ! is_user_logged_in() ) { return '<p>' . esc_html__( 'Please log in to view your classes.', 'prep-expert-exam-papers' ) . '</p>'; }
		$user_id = get_current_user_id(); $classes = self::ids( get_user_meta( $user_id, self::ENROLLED_USER_META, true ) );
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $candidate_id ) {
			if ( self::user_has_access( $candidate_id, $user_id ) && ! in_array( absint( $candidate_id ), $classes, true ) ) { $classes[] = absint( $candidate_id ); }
		}
		$out = '<table class="prep-expert-live-classes widefat striped"><thead><tr><th>Class</th><th>Date/Time</th><th>Status</th><th>Session Recording (Vimeo)</th></tr></thead><tbody>';
		foreach ( $classes as $class_id ) { 
			if ( self::POST_TYPE !== get_post_type( $class_id ) || ! self::user_has_access( $class_id, $user_id ) ) { continue; } 
			$start = self::class_time( $class_id ); 
			$record = get_post_meta( $class_id, self::ATTENDANCE_POST_META, true ); 
			$record = is_array( $record ) ? $record : array(); 
			$attended = isset( $record[ $user_id ] ); 
			$now = current_time( 'timestamp' ); 

			if ( $attended ) {
				$status = '<span style="color:green; font-weight:bold;">✓ Attended</span>';
			} elseif ( $start && $now >= ( $start - 900 ) && $now <= ( $start + 7200 ) ) {
				$join_url = wp_nonce_url( add_query_arg( array( 'pe_action' => 'join_live_class', 'class_id' => $class_id ), home_url( '/' ) ), 'prep_expert_join_' . $class_id );
				$status = '<a class="button" style="background:#28a745; color:#fff;" href="' . esc_url( $join_url ) . '">Join Class</a>';
			} elseif ( $start && $now > ( $start + 7200 ) ) {
				$status = '<span style="color:#dc3545;">Missed</span>';
			} else {
				$status = '<span style="color:#888;">Upcoming</span>';
			}

			$recording_url = self::field( $class_id, 'class_recording_url' ); 
			$recording_output = '—';
			if ( ! empty( $recording_url ) && wp_http_validate_url( $recording_url ) ) {
				$recording_output = '<a class="button prep-expert-live-recording-link" href="' . esc_url( $recording_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Watch Recording', 'prep-expert-exam-papers' ) . '</a>';
			} else {
				$recording_output = ( $start && $now < $start ) ? '<em>Available after class</em>' : '<em>No recording added</em>';
			}

			$out .= '<tr><td><strong>' . esc_html( get_the_title( $class_id ) ) . '</strong></td><td>' . esc_html( $start ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '—' ) . '</td><td>' . wp_kses_post( $status ) . '</td><td>' . wp_kses_post( $recording_output ) . '</td></tr>'; 
		}
		return $out . '</tbody></table>';
	}

	public static function handle_frontend_join_request() {
		if ( isset( $_GET['pe_action'] ) && 'join_live_class' === sanitize_key( wp_unslash( $_GET['pe_action'] ) ) ) {
			self::join_class();
		}
	}

	public static function join_class() {
		$class_id = absint( $_GET['class_id'] ?? 0 ); 
		if ( ! is_user_logged_in() || ! $class_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prep_expert_join_' . $class_id ) ) { 
			wp_die( esc_html__( 'Invalid join request.', 'prep-expert-exam-papers' ), 403 ); 
		}

		$current_user_id = get_current_user_id();
		$student_id      = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : $current_user_id;

		if ( $student_id !== $current_user_id ) {
			if ( ! class_exists( 'Prep_Expert_Parent_Child_Database' ) || ! Prep_Expert_Parent_Child_Database::can_parent_access_child( $current_user_id, $student_id ) ) {
				wp_die( esc_html__( 'You are not authorized to access this student\'s class.', 'prep-expert-exam-papers' ), 403 );
			}
		}

		if ( ! self::user_has_access( $class_id, $student_id ) ) {
			wp_die( esc_html__( 'You must purchase this class before joining.', 'prep-expert-exam-papers' ), 403 ); 
		}

		$start = self::class_time( $class_id ); 
		$now   = current_time( 'timestamp' ); 
		if ( ! $start || $now < ( $start - 900 ) || $now > ( $start + 7200 ) ) { 
			wp_die( esc_html__( 'This class is not currently available.', 'prep-expert-exam-papers' ), 403 ); 
		}

		// Record Attendance for the student
		$timestamp = current_time( 'mysql' ); 
		update_user_meta( $student_id, '_attended_class_' . $class_id, $timestamp );
		
		$record = get_post_meta( $class_id, self::ATTENDANCE_POST_META, true ); 
		$record = is_array( $record ) ? $record : array(); 
		$record[ $student_id ] = $timestamp;
		update_post_meta( $class_id, self::ATTENDANCE_POST_META, $record );

		// Parse Zoom URL / Meeting ID
		$meeting_id = preg_replace( '/[^0-9]/', '', (string) self::field( $class_id, 'zoom_meeting_id' ) ); 
		$url = $meeting_id ? 'https://zoom.us/j/' . $meeting_id : ''; 

		if ( ! $url || ! wp_http_validate_url( $url ) ) { 
			wp_die( esc_html__( 'The class link is unavailable.', 'prep-expert-exam-papers' ), 500 ); 
		} 

		wp_redirect( $url );
		exit;
	}

	public static function admin_menu() { add_submenu_page( 'edit.php?post_type=' . self::POST_TYPE, 'Attendance Reports', 'Attendance Reports', 'manage_options', 'prep-expert-live-attendance', array( __CLASS__, 'attendance_page' ) ); }

	public static function attendance_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; } echo '<div class="wrap"><h1>' . esc_html__( 'Live Class Attendance Reports', 'prep-expert-exam-papers' ) . '</h1><table class="widefat striped"><thead><tr><th>Class</th><th>Enrolled student</th><th>Attendance</th><th>Join timestamp</th></tr></thead><tbody>';
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $class ) { $enrolled = self::ids( get_post_meta( $class->ID, self::ENROLLED_POST_META, true ) ); $records = get_post_meta( $class->ID, self::ATTENDANCE_POST_META, true ); $records = is_array( $records ) ? $records : array(); foreach ( $enrolled as $user_id ) { $user = get_userdata( $user_id ); echo '<tr><td>' . esc_html( get_the_title( $class ) ) . '</td><td>' . esc_html( $user ? $user->user_email : 'User #' . $user_id ) . '</td><td>' . esc_html( isset( $records[ $user_id ] ) ? 'Attended' : 'Not attended' ) . '</td><td>' . esc_html( isset( $records[ $user_id ] ) ? $records[ $user_id ] : '—' ) . '</td></tr>'; } }
		echo '</tbody></table></div>';
	}
	
}
require_once __DIR__ . '/class-live-class-parent-extension.php';
Prep_Expert_Live_Class::init();
