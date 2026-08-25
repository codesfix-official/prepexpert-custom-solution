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

	public static function init() {
		// Checkout Fields & Validation
		//add_action( 'woocommerce_before_order_notes', array( __CLASS__, 'render_checkout_child_selector' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'add_child_dropdown_to_checkout_fields' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_child_selector' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_child_id_to_order' ), 10, 2 );

		// Idempotent Enrolment Action Hooks
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'enrol_child_to_live_class' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enrol_child_to_live_class' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'enrol_child_to_live_class' ) );
	}

	public static function add_child_dropdown_to_checkout_fields( $fields ) {
		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		$parent_id = get_current_user_id();
		$children  = Prep_Expert_Parent_Child_Database::active_children( $parent_id );

		// Agar parent ke paas linked children nahi hain to dropdown display na karein
		if ( empty( $children ) ) {
			return $fields;
		}

		$options = array( '' => __( '-- Select Student / Child --', 'prep-expert-exam-papers' ) );
		foreach ( $children as $child ) {
			$options[ $child['child_user_id'] ] = esc_html( $child['display_name'] . ' (' . $child['user_email'] . ')' );
		}

		// Billing Form fields array ke andar `enrolled_child_user_id` inject karein
		$fields['billing']['enrolled_child_user_id'] = array(
			'type'        => 'select',
			'label'       => __( 'Select Student / Child', 'prep-expert-exam-papers' ),
			'placeholder' => __( 'Select which child this course is for', 'prep-expert-exam-papers' ),
			'required'    => true,
			'class'       => array( 'form-row-wide', 'prep-child-select-field' ),
			'options'     => $options,
			'priority'    => 1, // Billing Form par sab se top (sab se upar) show hoga
		);

		return $fields;
	}

	public static function validate_checkout_child_selector() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$parent_id = get_current_user_id();
		$children  = Prep_Expert_Parent_Child_Database::active_children( $parent_id );

		// Elementor billing fields se submitted value check karein
		$selected_child = isset( $_POST['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_enrolled_child_user_id'] ) ) : ( isset( $_POST['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_child_user_id'] ) ) : '' );

		if ( ! empty( $children ) && empty( $selected_child ) ) {
			wc_add_notice( __( 'Please select a child/student to enrol in this programme.', 'prep-expert-exam-papers' ), 'error' );
		}
	}

	public static function save_child_id_to_order( $order, $data ) {
		$selected_child = isset( $_POST['billing_enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_enrolled_child_user_id'] ) ) : ( isset( $_POST['enrolled_child_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['enrolled_child_user_id'] ) ) : '' );

		if ( ! empty( $selected_child ) ) {
			$parent_id = get_current_user_id();
			$child_id  = absint( $selected_child );

			// Authorization Check
			if ( Prep_Expert_Parent_Child_Database::can_parent_access_child( $parent_id, $child_id ) ) {
				$order->update_meta_data( '_enrolled_child_user_id', $child_id );
			}
		}
	}

	public static function enrol_child_to_live_class( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency Lock
		if ( '1' === $order->get_meta( '_prep_expert_enrolment_processed' ) ) {
			return;
		}

		$selected_child = $order->get_meta( '_enrolled_child_user_id' );
		$target_user_id = $selected_child ? absint( $selected_child ) : $order->get_customer_id();

		if ( ! $target_user_id ) {
			return;
		}

		$processed = false;

		foreach ( $order->get_items() as $item ) {
			$product_id     = $item->get_product_id();
			$live_class_ids = get_post_meta( $product_id, '_linked_live_class_ids', true );

			if ( is_array( $live_class_ids ) ) {
				foreach ( $live_class_ids as $class_id ) {
					update_user_meta( $target_user_id, '_enrolled_live_class_' . absint( $class_id ), current_time( 'mysql' ) );
					$processed = true;
				}
			}
		}

		if ( $processed ) {
			$order->update_meta_data( '_prep_expert_enrolment_processed', '1' );
			$order->save();
		}
	}

	public static function render_parent_child_dashboard() {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please login to view your live classes.', 'prep-expert-exam-papers' ) . '</p>';
		}

		$current_user_id = get_current_user_id();
		$target_user_id  = $current_user_id;
		$children        = Prep_Expert_Parent_Child_Database::active_children( $current_user_id );

		ob_start();

		// Parent Authorization for Requested Child ID
		if ( ! empty( $children ) ) {
			$requested_child = isset( $_GET['child_id'] ) ? absint( $_GET['child_id'] ) : 0;

			if ( $requested_child && Prep_Expert_Parent_Child_Database::can_parent_access_child( $current_user_id, $requested_child ) ) {
				$target_user_id = $requested_child;
			} else {
				$target_user_id = $children[0]['child_user_id'];
			}

			echo '<div class="prep-child-switcher" style="margin-bottom:20px; padding:12px; background:#eef2ff; border-radius:6px; display:flex; align-items:center; gap:10px;">';
			echo '<strong>' . esc_html__( 'Select Child:', 'prep-expert-exam-papers' ) . '</strong>';
			echo '<select onchange="window.location.href=\'?child_id=\' + this.value;" style="padding:6px 12px; border-radius:4px; border:1px solid #cbd5e1;">';
			foreach ( $children as $child ) {
				$selected = ( (int) $child['child_user_id'] === (int) $target_user_id ) ? 'selected' : '';
				echo '<option value="' . esc_attr( $child['child_user_id'] ) . '" ' . $selected . '>' . esc_html( $child['display_name'] ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		// Query Live Classes
		$classes = get_posts( array(
			'post_type'      => 'live-class',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );

		$user_classes   = array();
		$total_enrolled = 0;
		$attended_count = 0;

		foreach ( $classes as $class_id ) {
			if ( get_user_meta( $target_user_id, '_enrolled_live_class_' . $class_id, true ) ) {
				$total_enrolled++;
				if ( get_user_meta( $target_user_id, '_attended_class_' . $class_id, true ) ) {
					$attended_count++;
				}
				$user_classes[] = $class_id;
			}
		}

		$remaining_sessions = max( 0, $total_enrolled - $attended_count );

		echo '<div class="prep-class-stats" style="display:flex; gap:15px; margin-bottom:20px;">';
		echo '<div style="flex:1; background:#f1f5f9; padding:12px; border-radius:6px; text-align:center;"><strong>Total Sessions:</strong> ' . esc_html( $total_enrolled ) . '</div>';
		echo '<div style="flex:1; background:#dcfce7; padding:12px; border-radius:6px; text-align:center; color:#166534;"><strong>Attended:</strong> ' . esc_html( $attended_count ) . '</div>';
		echo '<div style="flex:1; background:#fef3c7; padding:12px; border-radius:6px; text-align:center; color:#92400e;"><strong>Remaining Sessions:</strong> ' . esc_html( $remaining_sessions ) . '</div>';
		echo '</div>';

		if ( empty( $user_classes ) ) {
			echo '<p>' . esc_html__( 'No enrolled live classes found for this student.', 'prep-expert-exam-papers' ) . '</p>';
			return ob_get_clean();
		}

		echo '<table class="prep-live-class-table" style="width:100%; border-collapse:collapse; text-align:left;">';
		echo '<thead><tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">';
		echo '<th style="padding:10px;">Class Name</th>';
		echo '<th style="padding:10px;">Date & Time</th>';
		echo '<th style="padding:10px;">Status / Access</th>';
		echo '<th style="padding:10px;">Attendance</th>';
		echo '<th style="padding:10px;">Recording</th>';
		echo '</tr></thead><tbody>';

		$now = current_time( 'timestamp' );

		foreach ( $user_classes as $class_id ) {
			$title          = get_the_title( $class_id );
			$start          = Prep_Expert_Live_Class::class_time( $class_id );
			$formatted_date = $start ? wp_date( 'F j, Y - g:i A', $start ) : 'N/A';
			$attended       = get_user_meta( $target_user_id, '_attended_class_' . $class_id, true );
			$vimeo_url      = get_post_meta( $class_id, 'class_recording_url', true );

			$fifteen_mins = $start - 900;
			$two_hours    = $start + 7200;

			if ( $now >= $fifteen_mins && $now <= $two_hours ) {
				$join_nonce = wp_create_nonce( 'prep_expert_join_' . $class_id . '_' . $target_user_id );
				$join_url   = add_query_arg(
					array(
						'pe_action'  => 'join_live_class',
						'class_id'   => $class_id,
						'student_id' => $target_user_id,
						'_wpnonce'   => $join_nonce,
					),
					home_url( '/' )
				);
				$access_btn = '<a href="' . esc_url( $join_url ) . '" style="background:#28a745; color:#fff; padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:bold;">Join Class</a>';
			} elseif ( $now > $two_hours ) {
				$access_btn = '<span style="color:#64748b;">Ended</span>';
			} else {
				$access_btn = '<span style="color:#0284c7;">Upcoming</span>';
			}

			$attendance_status = $attended ? '<span style="color:#166534; font-weight:bold;">✓ Attended</span>' : ( $now > $two_hours ? '<span style="color:#dc3545;">Missed</span>' : 'Pending' );
			$recording         = $vimeo_url ? '<a href="' . esc_url( $vimeo_url ) . '" target="_blank" style="color:#4f46e5; text-decoration:underline;">Watch Recording</a>' : 'N/A';

			echo '<tr style="border-bottom:1px solid #e2e8f0;">';
			echo '<td style="padding:10px;"><strong>' . esc_html( $title ) . '</strong></td>';
			echo '<td style="padding:10px;">' . esc_html( $formatted_date ) . '</td>';
			echo '<td style="padding:10px;">' . $access_btn . '</td>';
			echo '<td style="padding:10px;">' . $attendance_status . '</td>';
			echo '<td style="padding:10px;">' . $recording . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		return ob_get_clean();
	}
}

add_action( 'plugins_loaded', array( 'Prep_Expert_Live_Class_Parent_Extension', 'init' ) );