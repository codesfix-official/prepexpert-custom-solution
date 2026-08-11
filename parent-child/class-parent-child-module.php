<?php
/** @package PrepExpertExamPapers */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Prep_Expert_Parent_Child_Module {
	const PARENT_ROLE = 'prep_parent';
	const CHILD_ROLE  = 'prep_child';
	const CAPABILITY  = 'prep_manage_children';
	const SHORTCODE   = 'prep_parent_children';

	private static $instance = null;
	private $route_rendered = false;
	private $shortcode_rendered = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_install' ), 5 );
		add_action( 'init', array( $this, 'handle_form_submit' ), 1 );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'admin_post_prep_add_child', array( $this, 'add_child' ) );
		add_action( 'admin_post_nopriv_prep_add_child', array( $this, 'add_child' ) );
		add_action( 'admin_post_prep_remove_child', array( $this, 'remove_child' ) );
		add_action( 'admin_post_nopriv_prep_remove_child', array( $this, 'remove_child' ) );
		add_filter( 'stm_lms_menu_items', array( $this, 'add_dashboard_menu_item' ) );
		add_action( 'stm_lms_template_account_main', array( $this, 'render_dashboard_page' ) );
		add_action( 'stm_lms_template_main', array( $this, 'render_dashboard_page' ) );
		add_filter( 'the_content', array( $this, 'render_root_route_content' ), 20 );
	}

	public function maybe_install() {
		$this->register_roles();
		if ( '1.0.0' !== get_option( 'prep_parent_child_db_version' ) ) {
			Prep_Expert_Parent_Child_Database::install();
		}
	}

	private function register_roles() {
		$parent = get_role( self::PARENT_ROLE );
		if ( ! $parent ) {
			add_role( self::PARENT_ROLE, __( 'Parent', 'prep-expert-exam-papers' ), array( 'read' => true, self::CAPABILITY => true ) );
		} elseif ( ! $parent->has_cap( self::CAPABILITY ) ) {
			$parent->add_cap( self::CAPABILITY );
		}
		if ( ! get_role( self::CHILD_ROLE ) ) {
			add_role( self::CHILD_ROLE, __( 'Child', 'prep-expert-exam-papers' ), array( 'read' => true ) );
		}
	}

	public function add_dashboard_menu_item( $menus ) {
		if ( ! $this->can_manage_children() ) {
			return $menus;
		}
		$current = class_exists( 'STM_LMS_User_Menu' ) ? STM_LMS_User_Menu::get_current_account_slug() : '';
		$menu_url = function_exists( 'ms_plugin_user_account_url' ) ? ms_plugin_user_account_url( 'prep-parent-children' ) : home_url( '/prep-parent-children/' );
		$menus[] = array( 'order' => 174, 'id' => 'prep-parent-children', 'slug' => 'prep-parent-children', 'lms_template' => 'account/main', 'menu_title' => esc_html__( 'My Children', 'prep-expert-exam-papers' ), 'menu_icon' => 'stmlms-menu-students', 'menu_url' => $menu_url, 'is_active' => 'prep-parent-children' === $current || $this->is_parent_children_request(), 'menu_place' => 'learning', 'section' => 'account' );
		return $menus;
	}

	/** Render the root-level route when the account page is configured at /. */
	public function render_root_route_content( $content ) {
		if ( $this->route_rendered || ! is_page() || ! $this->can_manage_children() || ! $this->is_parent_children_request() ) {
			return $content;
		}

		$this->route_rendered = true;
		return $content . do_shortcode( '[' . self::SHORTCODE . ']' );
	}

	public function handle_form_submit() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			return;
		}
		if ( ! isset( $_POST['prep_parent_child_form'] ) || '1' !== sanitize_key( wp_unslash( $_POST['prep_parent_child_form'] ) ) ) {
			return;
		}
		if ( ! isset( $_POST['action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		$this->log( 'parent-child form submission received', array(
			'action' => $action,
			'script' => isset( $_SERVER['SCRIPT_NAME'] ) ? basename( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '',
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
		) );
		if ( 'prep_add_child' === $action ) {
			$this->add_child();
			return;
		}
		if ( 'prep_remove_child' === $action ) {
			$this->remove_child();
		}
	}

	/** Render the module inside MasterStudy's existing account-main template. */
	public function render_dashboard_page() {
		if ( ! $this->can_manage_children() ) {
			return;
		}
		$current = class_exists( 'STM_LMS_User_Menu' ) ? STM_LMS_User_Menu::get_current_account_slug() : '';
		if ( 'prep-parent-children' === $current || $this->is_parent_children_request() ) {
			$this->route_rendered = true;
			echo do_shortcode( '[' . self::SHORTCODE . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode renderer escapes dynamic values.
		}
	}

	private function is_parent_children_request() {
		if ( isset( $_GET['section'] ) ) {
			return 'prep-parent-children' === sanitize_key( wp_unslash( $_GET['section'] ) );
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			if ( is_string( $path ) ) {
				$path = trim( $path, '/' );
				return preg_match( '#(^|/)(prep-parent-children)(/|$)#', $path ) === 1;
			}
		}
		return false;
	}

	public function add_child() {
		$parent_id = get_current_user_id();
		$this->log( 'add_child handler started', array( 'parent_id' => $parent_id ) );
		if ( ! $parent_id || ! $this->can_manage_children() ) {
			$this->log( 'add_child blocked: user cannot manage children', array( 'parent_id' => $parent_id, 'logged_in' => is_user_logged_in() ) );
			wp_die( esc_html__( 'You are not allowed to manage children.', 'prep-expert-exam-papers' ), 403 );
		}
		check_admin_referer( 'prep_add_child' );
		$email = isset( $_POST['child_email'] ) ? sanitize_email( wp_unslash( $_POST['child_email'] ) ) : '';
		$this->log( 'add_child email received', array( 'email_hash' => md5( strtolower( $email ) ), 'is_valid' => is_email( $email ) ) );
		if ( ! is_email( $email ) ) {
			$this->log( 'add_child failed: invalid email' );
			$this->redirect_notice( 'invalid_email' );
			return;
		}
		$child = get_user_by( 'email', $email );
		$this->log( 'add_child existing user lookup completed', array( 'found' => $child instanceof WP_User, 'child_id' => $child instanceof WP_User ? $child->ID : 0 ) );
		if ( ! $child ) {
			$child = $this->create_child_account( $email );
			if ( is_wp_error( $child ) ) {
				$this->log( 'add_child failed: child account creation returned WP_Error', array( 'error_code' => $child->get_error_code(), 'error_message' => $child->get_error_message() ) );
				$this->redirect_notice( $child->get_error_code() );
				return;
			}
			$this->log( 'add_child child account created', array( 'child_id' => $child->ID ) );
		}
		if ( (int) $child->ID === (int) $parent_id ) {
			$this->log( 'add_child failed: parent attempted to add self' );
			$this->redirect_notice( 'self_not_allowed' );
			return;
		}
		if ( in_array( self::PARENT_ROLE, (array) $child->roles, true ) ) {
			$this->log( 'add_child failed: target already has parent role', array( 'child_id' => $child->ID ) );
			$this->redirect_notice( 'parent_not_child' );
			return;
		}
		$result = Prep_Expert_Parent_Child_Database::add( $parent_id, $child->ID );
		if ( is_wp_error( $result ) ) {
			$this->log( 'add_child failed: relationship insert returned WP_Error', array( 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message(), 'parent_id' => $parent_id, 'child_id' => $child->ID ) );
			$this->redirect_notice( $result->get_error_code() );
			return;
		}
		$this->log( 'add_child relationship saved', array( 'relation_id' => $result, 'parent_id' => $parent_id, 'child_id' => $child->ID ) );
		$parent = get_userdata( $parent_id );
		if ( $parent instanceof WP_User ) {
			$parent->add_role( self::PARENT_ROLE );
			$this->log( 'add_child parent role assigned', array( 'parent_id' => $parent_id ) );
		}
		$child->add_role( self::CHILD_ROLE );
		$this->log( 'add_child child role assigned; redirecting success' , array( 'child_id' => $child->ID ) );
		wp_safe_redirect( add_query_arg( 'prep_child_notice', 'child_added', wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	public function remove_child() {
		$parent_id = get_current_user_id();
		if ( ! $parent_id || ! $this->can_manage_children() ) { wp_die( esc_html__( 'You are not allowed to manage children.', 'prep-expert-exam-papers' ), 403 ); }
		check_admin_referer( 'prep_remove_child' );
		$removed = Prep_Expert_Parent_Child_Database::remove( $parent_id, isset( $_POST['relation_id'] ) ? absint( $_POST['relation_id'] ) : 0 );
		wp_safe_redirect( add_query_arg( 'prep_child_notice', $removed ? 'child_removed' : 'child_not_found', wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	public function render_shortcode() {
		if ( $this->shortcode_rendered || ! $this->can_manage_children() ) {
			return '';
		}

		$this->shortcode_rendered = true;
		$children = Prep_Expert_Parent_Child_Database::active_children( get_current_user_id() );
		$progress = $this->get_child_progress( $children );
		ob_start(); ?>
		<div class="prep-parent-children"><h2><?php echo esc_html__( 'My Children', 'prep-expert-exam-papers' ); ?></h2>
		<?php if ( isset( $_GET['prep_child_notice'] ) ) : $notice = sanitize_key( wp_unslash( $_GET['prep_child_notice'] ) ); ?><p class="prep-parent-children__notice prep-parent-children__notice--<?php echo esc_attr( $this->notice_type( $notice ) ); ?>"><?php echo esc_html( $this->notice_message( $notice ) ); ?></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="prep_add_child"><input type="hidden" name="prep_parent_child_form" value="1"><?php wp_nonce_field( 'prep_add_child' ); ?><label for="prep-child-email"><?php echo esc_html__( 'Child email address', 'prep-expert-exam-papers' ); ?></label><input id="prep-child-email" type="email" name="child_email" required><button type="submit"><?php echo esc_html__( 'Add Child', 'prep-expert-exam-papers' ); ?></button></form>

		<?php if ( empty( $children ) ) : ?><p><?php echo esc_html__( 'No children have been added yet.', 'prep-expert-exam-papers' ); ?></p><?php else : ?><ul><?php foreach ( $children as $child ) : ?><li><span><?php echo esc_html( $child['display_name'] . ' — ' . $child['user_email'] ); ?></span><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="prep_remove_child"><input type="hidden" name="prep_parent_child_form" value="1"><input type="hidden" name="relation_id" value="<?php echo esc_attr( $child['id'] ); ?>"><?php wp_nonce_field( 'prep_remove_child' ); ?><button type="submit"><?php echo esc_html__( 'Remove', 'prep-expert-exam-papers' ); ?></button></form></li><?php endforeach; ?></ul>
			<h3><?php echo esc_html__( 'Child Course Progress', 'prep-expert-exam-papers' ); ?></h3><?php foreach ( $children as $child ) : ?><details class="prep-parent-children__report"><summary><?php echo esc_html( $child['display_name'] ); ?><span><?php echo esc_html( $child['user_email'] ); ?></span></summary><?php if ( empty( $progress[ $child['child_user_id'] ] ) ) : ?><p><?php echo esc_html__( 'No MasterStudy course activity is available for this child.', 'prep-expert-exam-papers' ); ?></p><?php else : ?><div class="prep-parent-children__table-wrap"><table><thead><tr><th><?php echo esc_html__( 'Course', 'prep-expert-exam-papers' ); ?></th><th><?php echo esc_html__( 'Progress', 'prep-expert-exam-papers' ); ?></th><th><?php echo esc_html__( 'Last activity', 'prep-expert-exam-papers' ); ?></th><th><?php echo esc_html__( 'Status', 'prep-expert-exam-papers' ); ?></th></tr></thead><tbody><?php foreach ( $progress[ $child['child_user_id'] ] as $course ) : ?><tr><td><?php echo esc_html( $course['title'] ); ?></td><td><div class="prep-parent-children__bar"><span style="width:<?php echo esc_attr( $course['progress'] ); ?>%"></span></div><?php echo esc_html( $course['progress'] . '%' ); ?></td><td><?php echo esc_html( $course['activity'] ); ?></td><td><span class="prep-parent-children__status prep-parent-children__status--<?php echo esc_attr( $course['status_class'] ); ?>"><?php echo esc_html( $course['status'] ); ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></details><?php endforeach; ?>
		<?php endif; ?></div>
		<style>.prep-parent-children{max-width:1000px;padding:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px}.prep-parent-children form{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:16px 0}.prep-parent-children label{width:100%;font-weight:600}.prep-parent-children input[type=email]{flex:1;min-width:220px;padding:10px;border:1px solid #cbd5e1;border-radius:6px}.prep-parent-children button{padding:10px 14px;border:0;border-radius:6px;background:#4338ca;color:#fff;cursor:pointer}.prep-parent-children ul{padding:0;list-style:none}.prep-parent-children li{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #e5e7eb}.prep-parent-children li form{margin:0}.prep-parent-children li button{background:#b91c1c}.prep-parent-children__notice{padding:10px;background:#f1f5f9}.prep-parent-children__notice--error{background:#fef2f2;color:#991b1b}.prep-parent-children__notice--success{background:#f0fdf4;color:#166534}.prep-parent-children__report{margin-top:14px;border:1px solid #e5e7eb;border-radius:8px;padding:12px}.prep-parent-children__report summary{cursor:pointer;font-weight:700}.prep-parent-children__report summary span{float:right;color:#64748b;font-weight:400}.prep-parent-children__table-wrap{overflow:auto;margin-top:12px}.prep-parent-children table{width:100%;border-collapse:collapse;min-width:650px}.prep-parent-children th,.prep-parent-children td{text-align:left;padding:10px;border-top:1px solid #e5e7eb;font-size:13px}.prep-parent-children th{font-size:11px;text-transform:uppercase;color:#64748b}.prep-parent-children__bar{display:inline-block;width:110px;height:7px;margin-right:6px;background:#e5e7eb;border-radius:9px;overflow:hidden;vertical-align:middle}.prep-parent-children__bar span{display:block;height:100%;background:#4f46e5}.prep-parent-children__status{padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.prep-parent-children__status--passed{background:#dcfce7;color:#166534}.prep-parent-children__status--progress{background:#fef3c7;color:#92400e}.prep-parent-children__status--not-started{background:#f1f5f9;color:#475569}</style></div>
		<?php return (string) ob_get_clean();
	}

	private function can_manage_children() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) ) {
			return false;
		}
		// Child accounts may never manage children.
		if ( in_array( self::CHILD_ROLE, (array) $user->roles, true ) ) {
			return false;
		}
		// Allow any logged-in non-child user. The parent role (and its CAPABILITY)
		// is assigned only after the first successful child add, so we cannot gate
		// on CAPABILITY here — doing so would prevent a user from adding their very
		// first child and therefore never becoming a parent.
		return true;
	}

	/**
	 * Create a child account for an email address that is not registered yet.
	 *
	 * @param string $email Sanitized email address.
	 * @return WP_User|WP_Error
	 */
	private function create_child_account( $email ) {
		$local_part = sanitize_user( (string) strtok( $email, '@' ), true );
		$local_part = '' !== $local_part ? $local_part : 'child';
		$user_login = $local_part;
		$suffix      = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $local_part . $suffix;
			++$suffix;
		}
		$user_id    = wp_insert_user(
			array(
				'user_login' => $user_login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => self::CHILD_ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->log( 'create_child_account wp_insert_user failed', array( 'error_code' => $user_id->get_error_code(), 'error_message' => $user_id->get_error_message() ) );
			return new WP_Error( 'child_registration_failed', __( 'The child account could not be created. Please try again.', 'prep-expert-exam-papers' ), array( 'source_error' => $user_id->get_error_code() ) );
		}
		$this->log( 'create_child_account wp_insert_user succeeded', array( 'user_id' => $user_id ) );

		$child = get_user_by( 'id', $user_id );
		if ( ! $child instanceof WP_User ) {
			$this->log( 'create_child_account failed: created user could not be loaded', array( 'user_id' => $user_id ) );
			return new WP_Error( 'child_registration_failed', __( 'The child account could not be loaded. Please try again.', 'prep-expert-exam-papers' ) );
		}

		if ( function_exists( 'wp_new_user_notification' ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return $child;
	}

	/**
	 * Write parent-child diagnostics to the WordPress debug log.
	 *
	 * @param string $message Diagnostic message.
	 * @param array  $context Optional context values.
	 * @return void
	 */
	private function log( $message, $context = array() ) {
		prep_expert_parent_child_write_log( $message, $context );
	}

	private function get_child_progress( $children ) {
		global $wpdb;
		$user_ids = array_map( 'absint', wp_list_pluck( $children, 'child_user_id' ) );
		if ( empty( $user_ids ) ) {
			return array();
		}
		$table = function_exists( 'stm_lms_user_courses_name' ) ? stm_lms_user_courses_name( $wpdb ) : $wpdb->prefix . 'stm_lms_user_courses';
		$marks = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		// Column is named `progress` in stm_lms_user_courses (not `progress_percent`).
		// $user_ids must be spread with ... so wpdb->prepare() receives individual arguments.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, course_id, progress, start_time FROM {$table} WHERE user_id IN ({$marks}) ORDER BY start_time ASC", ...$user_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$threshold = absint( class_exists( 'STM_LMS_Options' ) ? STM_LMS_Options::get_option( 'certificate_threshold', 70 ) : 70 );
		$report = array();
		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] );
			$course_id = absint( $row['course_id'] );
			$progress = min( 100, max( 0, absint( $row['progress'] ) ) );
			$times = get_user_meta( $user_id, 'last_progress_time', true );
			$times = is_array( $times ) ? $times : array();
			$timestamp = absint( $times[ $course_id ] ?? $row['start_time'] );
			$status = $progress >= $threshold ? __( 'Passed', 'prep-expert-exam-papers' ) : ( $progress > 0 ? __( 'In progress', 'prep-expert-exam-papers' ) : __( 'Not started', 'prep-expert-exam-papers' ) );
			$class  = $progress >= $threshold ? 'passed' : ( $progress > 0 ? 'progress' : 'not-started' );
			$report[ $user_id ][] = array( 'title' => get_the_title( $course_id ), 'progress' => $progress, 'activity' => $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : __( 'Not started', 'prep-expert-exam-papers' ), 'status' => $status, 'status_class' => $class );
		}
		return $report;
	}

	private function notice_type( $notice ) {
		return in_array( $notice, array( 'child_added', 'child_removed' ), true ) ? 'success' : 'error';
	}

	private function notice_message( $notice ) {
		$messages = array(
			'child_added'          => __( 'Child added successfully.', 'prep-expert-exam-papers' ),
			'child_removed'        => __( 'Child removed successfully.', 'prep-expert-exam-papers' ),
			'invalid_email'        => __( 'Please enter a valid child email address.', 'prep-expert-exam-papers' ),
			'child_not_found'      => __( 'No registered user was found with that email address.', 'prep-expert-exam-papers' ),
			'child_registration_failed' => __( 'The child account could not be created. Please try again.', 'prep-expert-exam-papers' ),
			'self_not_allowed'     => __( 'You cannot add your own account as a child.', 'prep-expert-exam-papers' ),
			'parent_not_child'     => __( 'A parent account cannot be added as a child.', 'prep-expert-exam-papers' ),
			'child_already_linked' => __( 'This child already belongs to a parent account.', 'prep-expert-exam-papers' ),
			'relationship_not_saved' => __( 'The child relationship could not be saved. Please try again.', 'prep-expert-exam-papers' ),
		);
		return $messages[ $notice ] ?? __( 'The requested action could not be completed.', 'prep-expert-exam-papers' );
	}

	private function redirect_notice( $notice ) {
		wp_safe_redirect( add_query_arg( 'prep_child_notice', sanitize_key( $notice ), wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}
}
