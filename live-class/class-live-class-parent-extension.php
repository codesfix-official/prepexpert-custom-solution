<?php
/**
 * WooCommerce Parent-Child Checkout & Dashboard Extensions
 *
 * @package PrepExpertExamPapers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prep_Expert_Live_Class_Parent_Extension {

	private static $route_rendered = false;

	public static function init() {
		// Checkout Fields & Validation
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_child_dropdown_to_checkout_fields' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_child_selector' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_child_id_to_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_child_id_to_order_meta_fallback' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'prep_expert_enrol_child_in_masterstudy_lms' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'prep_expert_enrol_child_in_masterstudy_lms' ), 20, 1 );

		// MasterStudy LMS Account Template Hooks
		add_action( 'stm_lms_template_account_main', array( __CLASS__, 'render_dashboard_page' ) );
		add_action( 'stm_lms_template_main', array( __CLASS__, 'render_dashboard_page' ) );
		add_filter( 'the_content', array( __CLASS__, 'render_root_route_content' ), 20 );
	}

	public static function add_child_dropdown_to_checkout_fields( $fields ) {
		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		$parent_id = get_current_user_id();
		$children  = class_exists( 'Prep_Expert_Parent_Child_Database' ) ? Prep_Expert_Parent_Child_Database::active_children( $parent_id ) : array();

		// If parent has no linked children, do not display the child dropdown
		if ( empty( $children ) ) {
			return $fields;
		}

		$options = array( '' => __( '-- Select Student / Child --', 'prep-expert-exam-papers' ) );
		foreach ( $children as $child ) {
			$options[ $child['child_user_id'] ] = esc_html( $child['display_name'] . ' (' . $child['user_email'] . ')' );
		}

		// Inject `enrolled_child_user_id` into billing form fields
		$fields['billing']['enrolled_child_user_id'] = array(
			'type'        => 'select',
			'label'       => __( 'Select Student / Child', 'prep-expert-exam-papers' ),
			'placeholder' => __( 'Select which child this course is for', 'prep-expert-exam-papers' ),
			'required'    => true,
			'class'       => array( 'form-row-wide', 'prep-child-select-field' ),
			'options'     => $options,
			'priority'    => 1,
		);

		return $fields;
	}

	public static function validate_checkout_child_selector() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$parent_id = get_current_user_id();
		$children  = class_exists( 'Prep_Expert_Parent_Child_Database' ) ? Prep_Expert_Parent_Child_Database::active_children( $parent_id ) : array();

		$selected_child = isset( $_POST['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_enrolled_child_user_id'] ) ) : ( isset( $_POST['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_child_user_id'] ) ) : '' );

		if ( ! empty( $children ) ) {
			if ( empty( $selected_child ) ) {
				wc_add_notice( __( 'Please select a child/student to enrol in this programme.', 'prep-expert-exam-papers' ), 'error' );
			} elseif ( ! Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_id, absint( $selected_child ) ) ) {
				wc_add_notice( __( 'Invalid student / child selection.', 'prep-expert-exam-papers' ), 'error' );
			}
		}
	}

	public static function save_child_id_to_order( $order, $data ) {
		$selected_child = isset( $_POST['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_enrolled_child_user_id'] ) ) : ( isset( $_POST['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_child_user_id'] ) ) : '' );
		if ( empty( $selected_child ) && is_array( $data ) ) {
			$selected_child = isset( $data['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $data['billing_enrolled_child_user_id'] ) ) : ( isset( $data['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $data['enrolled_child_user_id'] ) ) : '' );
		}

		if ( ! empty( $selected_child ) ) {
			$parent_id = get_current_user_id();
			$child_id  = absint( $selected_child );

			// Authorization Check
			if ( class_exists( 'Prep_Expert_Parent_Child_Database' ) && Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_id, $child_id ) ) {
				$order->update_meta_data( '_enrolled_child_user_id', $child_id );
			}
		}
	}

	public static function save_child_id_to_order_meta_fallback( $order_id, $data ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return;
		}

		$selected_child = isset( $_POST['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_enrolled_child_user_id'] ) ) : ( isset( $_POST['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_child_user_id'] ) ) : '' );
		if ( empty( $selected_child ) && is_array( $data ) ) {
			$selected_child = isset( $data['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $data['billing_enrolled_child_user_id'] ) ) : ( isset( $data['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $data['enrolled_child_user_id'] ) ) : '' );
		}

		if ( ! empty( $selected_child ) ) {
			$parent_id = get_current_user_id();
			$child_id  = absint( $selected_child );

			// Authorization Check
			if ( class_exists( 'Prep_Expert_Parent_Child_Database' ) && Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_id, $child_id ) ) {
				$order->update_meta_data( '_enrolled_child_user_id', $child_id );
				$order->save();
			}
		}
	}

	public static function enrol_child_to_live_class( $order_id ) {
		Prep_Expert_Live_Class::enrol_order_user( $order_id );
	}

	public static function is_live_classes_request() {
		if ( isset( $_GET['section'] ) && 'prep-live-classes' === sanitize_key( wp_unslash( $_GET['section'] ) ) ) {
			return true;
		}

		if ( is_page( 'student-live-classes' ) ) {
			return true;
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			if ( is_string( $path ) ) {
				$path = trim( $path, '/' );
				return preg_match( '#(^|/)(prep-live-classes|student-live-classes)(/|$)#', $path ) === 1;
			}
		}

		return false;
	}

	public static function render_dashboard_page() {
		$current = class_exists( 'STM_LMS_User_Menu' ) ? STM_LMS_User_Menu::get_current_account_slug() : '';
		if ( 'prep-live-classes' === $current || self::is_live_classes_request() ) {
			self::$route_rendered = true;
			echo self::render_parent_child_dashboard(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function render_root_route_content( $content ) {
		if ( self::$route_rendered || ! is_page() || ! self::is_live_classes_request() ) {
			return $content;
		}
		self::$route_rendered = true;
		return $content . self::render_parent_child_dashboard();
	}

	/**
	 * Render the shortcode output once and mark the route as rendered.
	 * This prevents the same dashboard from also being appended by the_content.
	 *
	 * @return string Dashboard HTML.
	 */
	public static function render_shortcode_dashboard() {
		if ( self::$route_rendered ) {
			return '';
		}

		self::$route_rendered = true;
		return self::render_parent_child_dashboard();
	}

	public static function render_parent_child_dashboard() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to view your live classes.', 'prep-expert-exam-papers' ) . '</p>';
		}

		if ( defined( 'PREP_EXPERT_EXAM_PAPERS_FILE' ) ) {
			$css_path = plugin_dir_path( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
			$css_url  = plugin_dir_url( PREP_EXPERT_EXAM_PAPERS_FILE ) . 'assets/css/prep-expert-live-classes.css';
			wp_enqueue_style( 'prep-expert-live-classes', $css_url, array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0' );
		}

		$current_user_id = get_current_user_id();
		$target_user_id  = $current_user_id;
		$children        = class_exists( 'Prep_Expert_Parent_Child_Database' ) ? Prep_Expert_Parent_Child_Database::active_children( $current_user_id ) : array();

		ob_start();

		// Parent Authorization for Requested Child ID
		if ( ! empty( $children ) ) {
			$requested_child = isset( $_GET['child_id'] ) ? absint( $_GET['child_id'] ) : 0;

			if ( $requested_child && Prep_Expert_Parent_Child_Database::can_parent_access_child( $current_user_id, $requested_child ) ) {
				$target_user_id = $requested_child;
			} else {
				$target_user_id = (int) $children[0]['child_user_id'];
			}

			echo '<div class="prep-child-switcher" style="margin-bottom:20px; padding:12px; background:#eef2ff; border-radius:6px; display:flex; align-items:center; gap:10px;">';
			echo '<strong>' . esc_html__( 'Select Student / Child:', 'prep-expert-exam-papers' ) . '</strong>';
			echo '<select onchange="window.location.href=\'?child_id=\' + this.value;" style="padding:6px 12px; border-radius:4px; border:1px solid #cbd5e1;">';
			foreach ( $children as $child ) {
				$selected = ( (int) $child['child_user_id'] === (int) $target_user_id ) ? 'selected' : '';
				echo '<option value="' . esc_attr( $child['child_user_id'] ) . '" ' . $selected . '>' . esc_html( $child['display_name'] . ' (' . $child['user_email'] . ')' ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		// Query all published Live Classes
		$classes = get_posts( array(
			'post_type'      => Prep_Expert_Live_Class::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'ASC',
		) );

		$user_classes   = array();
		$total_enrolled = 0;
		$attended_count = 0;

		foreach ( $classes as $class_id ) {
			$class_id = absint( $class_id );
			if ( Prep_Expert_Live_Class::user_has_access( $class_id, $target_user_id ) ) {
				$total_enrolled++;

				$attended = get_user_meta( $target_user_id, '_attended_class_' . $class_id, true );
				if ( ! $attended ) {
					$records  = get_post_meta( $class_id, Prep_Expert_Live_Class::ATTENDANCE_POST_META, true );
					$attended = ( is_array( $records ) && isset( $records[ $target_user_id ] ) );
				}

				if ( $attended ) {
					$attended_count++;
				}
				$user_classes[] = $class_id;
			}
		}

		$remaining_sessions = max( 0, $total_enrolled - $attended_count );

		echo '<div class="prep-class-stats" style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">';
		echo '<div style="flex:1; min-width:140px; background:#f1f5f9; padding:12px; border-radius:6px; text-align:center;"><strong>' . esc_html__( 'Total Sessions:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $total_enrolled ) . '</div>';
		echo '<div style="flex:1; min-width:140px; background:#dcfce7; padding:12px; border-radius:6px; text-align:center; color:#166534;"><strong>' . esc_html__( 'Attended:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $attended_count ) . '</div>';
		echo '<div style="flex:1; min-width:140px; background:#fef3c7; padding:12px; border-radius:6px; text-align:center; color:#92400e;"><strong>' . esc_html__( 'Remaining Sessions:', 'prep-expert-exam-papers' ) . '</strong> ' . esc_html( $remaining_sessions ) . '</div>';
		echo '</div>';

		if ( empty( $user_classes ) ) {
			$empty_msg = ! empty( $children )
				? __( 'No enrolled live classes found for this student.', 'prep-expert-exam-papers' )
				: __( 'No enrolled live classes found for your account.', 'prep-expert-exam-papers' );
			echo '<p>' . esc_html( $empty_msg ) . '</p>';
			return ob_get_clean();
		}

		echo '<div style="overflow-x:auto;">';
		echo '<table class="prep-live-class-table widefat striped" style="width:100%; border-collapse:collapse; text-align:left; min-width:650px;">';
		echo '<thead><tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">';
		echo '<th style="padding:10px;">' . esc_html__( 'Class Name', 'prep-expert-exam-papers' ) . '</th>';
		echo '<th style="padding:10px;">' . esc_html__( 'Date & Time', 'prep-expert-exam-papers' ) . '</th>';
		echo '<th style="padding:10px;">' . esc_html__( 'Status / Access', 'prep-expert-exam-papers' ) . '</th>';
		echo '<th style="padding:10px;">' . esc_html__( 'Attendance', 'prep-expert-exam-papers' ) . '</th>';
		echo '<th style="padding:10px;">' . esc_html__( 'Recording', 'prep-expert-exam-papers' ) . '</th>';
		echo '</tr></thead><tbody>';

		$now = current_time( 'timestamp' );

		foreach ( $user_classes as $class_id ) {
			$title          = get_the_title( $class_id );
			$start          = Prep_Expert_Live_Class::class_time( $class_id );
			$formatted_date = $start ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $start ) : '—';

			$attended = get_user_meta( $target_user_id, '_attended_class_' . $class_id, true );
			if ( ! $attended ) {
				$records  = get_post_meta( $class_id, Prep_Expert_Live_Class::ATTENDANCE_POST_META, true );
				$attended = ( is_array( $records ) && isset( $records[ $target_user_id ] ) );
			}

			$recording_url = Prep_Expert_Live_Class::field( $class_id, 'class_recording_url' );
			if ( empty( $recording_url ) ) {
				$recording_url = get_post_meta( $class_id, 'class_recording_url', true );
			}

			$fifteen_mins = $start ? ( $start - 900 ) : 0;
			$two_hours    = $start ? ( $start + 7200 ) : 0;

			if ( $attended ) {
				$access_btn = '<span style="color:#166534; font-weight:bold;">' . esc_html__( 'Completed', 'prep-expert-exam-papers' ) . '</span>';
			} elseif ( $start && $now >= $fifteen_mins && $now <= $two_hours ) {
				$join_nonce = wp_create_nonce( 'prep_expert_join_' . $class_id );
				$join_args  = array(
					'pe_action' => 'join_live_class',
					'class_id'  => $class_id,
					'_wpnonce'  => $join_nonce,
				);
				if ( $target_user_id !== $current_user_id ) {
					$join_args['student_id'] = $target_user_id;
				}
				$join_url   = add_query_arg( $join_args, home_url( '/' ) );
				$access_btn = '<a href="' . esc_url( $join_url ) . '" style="background:#28a745; color:#fff; padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:bold; display:inline-block;">' . esc_html__( 'Join Class', 'prep-expert-exam-papers' ) . '</a>';
			} elseif ( $start && $now > $two_hours ) {
				$access_btn = '<span style="color:#64748b;">' . esc_html__( 'Ended', 'prep-expert-exam-papers' ) . '</span>';
			} else {
				$access_btn = '<span style="color:#0284c7;">' . esc_html__( 'Upcoming', 'prep-expert-exam-papers' ) . '</span>';
			}

			$attendance_status = $attended ? '<span style="color:#166534; font-weight:bold;">✓ ' . esc_html__( 'Attended', 'prep-expert-exam-papers' ) . '</span>' : ( ( $start && $now > $two_hours ) ? '<span style="color:#dc3545;">' . esc_html__( 'Missed', 'prep-expert-exam-papers' ) . '</span>' : esc_html__( 'Pending', 'prep-expert-exam-papers' ) );

			$recording = '—';
			if ( ! empty( $recording_url ) && wp_http_validate_url( $recording_url ) ) {
				$recording = '<a href="' . esc_url( $recording_url ) . '" target="_blank" rel="noopener noreferrer" style="color:#4f46e5; text-decoration:underline; font-weight:500;">' . esc_html__( 'Watch Recording', 'prep-expert-exam-papers' ) . '</a>';
			} elseif ( $start && $now < $start ) {
				$recording = '<em style="color:#94a3b8;">' . esc_html__( 'Available after class', 'prep-expert-exam-papers' ) . '</em>';
			} else {
				$recording = '<em style="color:#94a3b8;">' . esc_html__( 'No recording added', 'prep-expert-exam-papers' ) . '</em>';
			}

			echo '<tr style="border-bottom:1px solid #e2e8f0;">';
			echo '<td style="padding:10px;"><strong>' . esc_html( $title ) . '</strong></td>';
			echo '<td style="padding:10px;">' . esc_html( $formatted_date ) . '</td>';
			echo '<td style="padding:10px;">' . $access_btn . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td style="padding:10px;">' . $attendance_status . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td style="padding:10px;">' . $recording . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		return ob_get_clean();
	}


	public static function prep_expert_enrol_child_in_masterstudy_lms( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( absint( $order_id ) );
	if ( ! $order ) {
		return;
	}

	// 2. Extract Assigned Child User ID from Order Meta
	$child_user_id = $order->get_meta( '_enrolled_child_user_id' );
	if ( empty( $child_user_id ) ) {
		$child_user_id = get_post_meta( $order_id, '_prep_assigned_child_id', true );
	}

	$child_user_id  = absint( $child_user_id );
	$parent_user_id = absint( $order->get_customer_id() );
	if ( ! $child_user_id || ! $parent_user_id || ! class_exists( 'Prep_Expert_Parent_Child_Database' ) || ! Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_user_id, $child_user_id ) || $child_user_id === $parent_user_id ) {
		return; // Exit if no child was selected during checkout
	}

	// 3. Loop through order products and identify MasterStudy LMS Courses
	foreach ( $order->get_items() as $item ) {
		$product_id = $item->get_product_id();
		// MasterStudy LMS Product-to-Course Meta mapping check
		$course_id = absint( get_post_meta( $product_id, 'stm_lms_product_id', true ) );
		if ( empty( $course_id ) ) {
			// Fallback: If Product itself is published as Course CPT type
			$course_id = ( 'stm-courses' === get_post_type( $product_id ) ) ? $product_id : 0;
		}

		// If linked MasterStudy Course found
		if ( $course_id > 0 ) {
			// A. Update MasterStudy LMS User Meta (`stm_lms_courses`)
			$user_courses = get_user_meta( $child_user_id, 'stm_lms_courses', true );
			if ( ! is_array( $user_courses ) ) {
				$user_courses = array();
			}

			$user_courses = array_values( array_unique( array_map( 'absint', $user_courses ) ) );
			if ( ! in_array( $course_id, $user_courses, true ) ) {
				$user_courses[] = $course_id;
				update_user_meta( $child_user_id, 'stm_lms_courses', $user_courses );
			}

			// B. Direct Insert/Sync into MasterStudy LMS Database Table (`wp_stm_lms_user_courses`)
			global $wpdb;
			$table_name = $wpdb->prefix . 'stm_lms_user_courses';

			// Check if table exists before executing query
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT user_course_id FROM {$table_name} WHERE user_id = %d AND course_id = %d",
						$child_user_id,
						$course_id
					)
				);

				if ( ! $existing ) {
					$wpdb->insert(
						$table_name,
						array(
							'user_id'          => absint( $child_user_id ),
							'course_id'        => absint( $course_id ),
							'current_lesson_id'=> 0,
							'progress_percent' => 0,
							'status'           => 'enrolled',
							'start_time'       => time(),
						),
						array( '%d', '%d', '%d', '%d', '%s', '%d' )
					);
				}
			}

			// C. Remove Course Access from Parent Account (Clean Up)
			if ( $parent_user_id && $parent_user_id !== $child_user_id ) {
				$parent_courses = get_user_meta( $parent_user_id, 'stm_lms_courses', true );
				if ( is_array( $parent_courses ) && in_array( $course_id, array_map( 'absint', $parent_courses ), true ) ) {
					$parent_courses = array_diff( array_map( 'absint', $parent_courses ), array( $course_id ) );
					update_user_meta( $parent_user_id, 'stm_lms_courses', array_values( $parent_courses ) );
				}
			}
		}
	}
}
}

add_action( 'plugins_loaded', array( 'Prep_Expert_Live_Class_Parent_Extension', 'init' ) );
