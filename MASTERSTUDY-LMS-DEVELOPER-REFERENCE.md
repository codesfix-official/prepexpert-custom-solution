# MasterStudy LMS Developer Reference Guide

> Source snapshot reviewed: `masterstudy-lms-learning-management-system` 3.7.41 and `masterstudy-lms-learning-management-system-pro` 4.8.23, installed under `/opt/lampp/htdocs/plugin-dev/wp-content/plugins/`. This is a source-backed guide for extension development; it is not an API-stability promise. Always guard Pro-only integrations with `defined( 'STM_LMS_PRO_VERSION' )` and `function_exists()`/`class_exists()` checks.

## 1. Architecture and entry points

The free plugin entry file defines `MS_LMS_VERSION`, `MS_LMS_FILE`, `MS_LMS_PATH`, and `MS_LMS_URL`, loads Composer autoloading, `includes/init.php`, and `_core/init.php`. The Pro entry file defines `STM_LMS_PRO_*` constants, loads its Composer autoloader and `includes/init.php`, and is layered on top of the free plugin.

The modern free-plugin root is `MasterStudy\\Lms\\Plugin`, constructed by the bootstrap with a `MasterStudy\\Lms\\Routing\\Router`. It registers taxonomies, REST routes, search handlers, and enabled `Addon` objects. `masterstudy_lms_plugin_loaded` receives this Plugin instance after registration. The legacy `_core` layer remains active and supplies the `STM_LMS_*` procedural/static API, templates, AJAX handlers, settings, WooCommerce integration, and compatibility code.

Important architectural services/classes:

| Area | Classes / API | Extension implication |
|---|---|---|
| Bootstrap | `MasterStudy\\Lms\\Plugin`, `MasterStudy\\Lms\\Plugin\\Addons`, `MasterStudy\\Lms\\Routing\\Router` | Register after the plugin-loaded action; do not assume Pro is loaded. |
| Persistence/query | `CourseRepository`, `LessonRepository`, `CurriculumRepository`, `CurriculumSectionRepository`, `CurriculumMaterialRepository`, `QuestionRepository`, `CoursePlayerRepository`, `Admin*Repository` | Prefer repositories and existing helpers over direct SQL/meta writes. |
| HTTP | `MasterStudy\\Lms\\Http\\Controllers\\*`, REST `Router`, serializers | REST routes are dispatched centrally through the router. |
| Legacy public API | `STM_LMS_Options`, `STM_LMS_Course`, `STM_LMS_User`, `STM_LMS_Order`, `STM_LMS_Cart`, `STM_LMS_Checkout`, `STM_LMS_Payouts` and `stm_lms_*` functions | These are widely used by the installed code, but some are legacy; feature-detect them. |
| Pro | `STM_LMS_PRO_*` bootstrap, addon classes such as `EnterpriseCourses`, `CourseBundle`, `Subscriptions`, `CertificateBuilder`, `GoogleMeet`, `AiLab` | Pro features are independently enabled and may not be loaded. |

The addon registry is controlled by the `stm_lms_addons` option and `Addons::enabled_addons()`. The free code exposes `stm_lms_available_addons` and `masterstudy_lms_plugin_addons`; the latter is the modern addon-registration seam.

## 2. Data models and storage

### WordPress post types and taxonomies

Core post-type constants are defined in `includes/Plugin/PostType.php`:

| Post type | Purpose |
|---|---|
| `stm-courses` | Courses |
| `stm-lessons` | Lessons |
| `stm-assignments` | Assignments (feature/addon dependent) |
| `stm-course-bundles` | Course bundles (Pro) |
| `stm-ent-groups` | Enterprise groups (Pro) |
| `stm-certificates` | Certificates (Pro certificate builder) |
| `stm-questions` | Quiz questions in the legacy/core model |
| `stm-quizzes` | Quizzes in the legacy/core model |

Core taxonomies are `stm_lms_course_taxonomy` and `stm_lms_question_taxonomy`; the course taxonomy rewrite slug is configurable through `courses_categories_slug`. Pro conditionally adds feature-specific post types/taxonomies, including Zoom meetings and certificate-builder data.

The principal data model is normal WordPress posts plus post meta. Curriculum is represented by course/section/material relationships and serialized arrays; do not infer a stable meta schema from one template. Use repository hydration filters (`masterstudy_lms_course_hydrate`, `masterstudy_lms_lesson_hydrate`, `masterstudy_lms_question_hydrate`) where possible.

### Custom database tables

The Pro Subscriptions addon uses prefixed custom tables through helper functions rather than hard-coded names. The relevant repositories are `SubscriptionPlanRepository`, `SubscriptionPlanItemRepository`, `SubscriptionRepository`, `SubscriptionMetaRepository`, and payment-history repositories. The table families include subscription plans, plan items, subscriptions, subscription meta, and subscription payment history. Resolve names with the installed helpers (`stm_lms_subscription_*_table_name( $wpdb )`) so multisite/prefixes work. Other analytics/payout features also use repository/table helpers; search the active addon before querying directly.

### Options and settings

The main aggregate setting is `stm_lms_settings`, normally accessed through `STM_LMS_Options::get_option( $key, $default )`. Frequently used keys include `payment_methods`, `currency_code`, `transactions_currency`, `currency_symbol`, `currency_position`, `courses_per_page`, `course_premoderation`, `course_tab_reviews`, `course_style`, `quiz_attempts`, `retry_after_passing`, `show_attempts_history`, `grades_table`, `grades_display`, `enable_featured_courses`, `enable_lazyload`, `redirect_after_purchase`, `restrict_registration`, `courses_categories_slug`, and `instructors_certificates`.

Other observed options include `stm_lms_addons`, `stm_lms_business_type`, `stm_lms_coming_soon_settings`, `stm_lms_shareware_settings`, `stm_lms_ai_enabled_for_all`, `stm_lms_google_meet_settings`, Pro credential/token options, `grades_table`, AI usage/API/model options, and addon-specific settings. Treat all settings as untrusted configuration: cast booleans/integers and use defaults.

Common meta families are course pricing (`price`, `sale_price` and course options), curriculum/lesson fields, quiz/attempt/progress fields, user-course/order records, assignment submissions, bundle membership, enterprise group membership, certificate fields, and integration IDs such as Udemy/Google Meet/Zoom. Exact keys are distributed across the corresponding repository/addon; extension code should use the repository or the documented filter rather than bulk-copying private meta.

## 3. Execution flow

1. WordPress loads free LMS; its bootstrap loads Composer, modern services, and legacy `_core` files. Pro then loads if active and registers enabled addons.
2. Admin or frontend JavaScript calls a REST route, `admin-ajax.php`, WooCommerce checkout, or a legacy LMS endpoint. Nonces/capabilities are checked by the relevant controller/handler.
3. The controller/repository validates and maps request data, reads options and post/meta records, and writes posts, meta, cart/order records, or Pro tables.
4. Domain actions fire around the mutation: save course/lesson/quiz/assignment, curriculum material create/update/delete, cart add/remove, order accepted/completed, progress/lesson/quiz events, or subscription/payment state changes.
5. Filters shape input, settings, serialized output, prices, access checks, templates, and gateway data before the response is returned.
6. Deferred work is handled by WordPress cron: coming-soon notifications, subscription expiry/reminders, payment/webhook follow-up, and addon-specific scheduled tasks. Webhooks enter through REST/controller code and then reuse the subscription/order domain actions.

## 4. Core action hooks

The following are MasterStudy-owned or MasterStudy-specific actions found in the installed source. Arguments are listed at the primary trigger; repeated triggers share the same contract unless the source location differs.

| Action | Arguments / trigger |
|---|---|
| `masterstudy_lms_plugin_loaded` | `$plugin`; after modern bootstrap registration. |
| `masterstudy_lms_course_saved` | `$course_id`, `$data`; course save. |
| `masterstudy_lms_course_price_updated` | `$course_id`, price-related data; course price mutation. |
| `masterstudy_lms_course_update_access` | course/user access identifiers; access update. |
| `masterstudy_lms_save_lesson` | `$post_id`, `$data`; lesson repository save. |
| `masterstudy_lms_save_quiz` | `$post_id`, `$data`; quiz save. |
| `masterstudy_lms_save_assignment` | assignment ID/data; assignment save. |
| `masterstudy_lms_curriculum_material_created/updated/before_delete` | material/section/course identifiers as passed by `CurriculumSectionRepository`. |
| `stm_lms_progress_updated` | progress/user/course payload; student progress mutation. |
| `stm_lms_lesson_started`, `stm_lms_lesson_passed` | user/course/lesson context; course-player events. |
| `masterstudy_lms_user_quiz_added/deleted` | quiz/user/attempt context; quiz attempt persistence. |
| `masterstudy_plugin_student_course_completion` | course/user completion context. |
| `stm_lms_before_add_to_cart`, `stm_lms_after_add_to_cart` | `$item_id`, `$user_id` (plus cart context at some call sites). |
| `stm_lms_delete_from_cart`, `stm_lms_order_remove` | cart/order item context. |
| `stm_lms_order_accepted`, `masterstudy_lms_order_completed` | user/order/cart data; payment/order success paths. |
| `stm_lms_create_order_line_item` | line-item/order context; order line construction. |
| `masterstudy_lms_course_player_register_assets` | player asset/context data; course-player enqueue. |
| `masterstudy_lms_course_player_update_user_current_lesson` | user/course/lesson context; current-lesson update. |
| `stm_lms_user_registered`, `stm_lms_after_user_register` | registered user/context. |
| `stm_lms_login_end` | login result/user context. |
| `stm_lms_change_course_status`, `stm_lms_course_rejected` | course ID/status context. |
| `stm_lms_single_course_start`, `stm_lms_single_bundle_start` | displayed course/bundle context. |
| `stm_lms_template_main`, `stm_lms_template_main_after` | template name/data; template rendering boundaries. |
| `stm_lms_user_course_added_{$course_id}` | dynamic per-course enrollment action. |
| `stm_lms_subscription_created/activated/updated/cancelled/expired/expires_soon/reactivated/suspended` | generally `$user_id`, `$subscription_id`; `updated` also status, and exact payment/webhook variants should be checked at the call site. |
| `masterstudy_lms_subscription_payment_succeeded/completed/failed/refunded/refunded` | user/subscription/order IDs as passed by gateway/webhook. |
| `masterstudy_lms_gateway_subscription_cancelled` | gateway name, user ID, subscription ID. |
| `stm_lms_adding_enterprice_groups`, `stm_lms_group_updated` | group ID; update also new/old email data. |
| `stm_lms_announcement_ready_to_send` | announcement data; notification dispatch. |
| `stm_lms_certificate_generated` | certificate/user/course context. |
| `masterstudy_before_save_certificate*`, `masterstudy_before_delete_certificate*`, `masterstudy_before_upload_certificate_images` | certificate-builder mutation context; several have no arguments. |
| `stm_lms_google_classroom_course_imported` | imported course/context. |
| `stm_zoom_after_create_meeting`, `stm_zoom_after_update_meeting` | meeting data/ID. |
| `masterstudy_lms_before_delete_user_course`, `masterstudy_lms_after_delete_user_course` | user/course enrollment context. |

Additional UI/template/import actions are indexed exhaustively in Appendix A. Actions named `stm_wp_import_*`, `stm_wpcfto_*`, `wpml_*`, `woocommerce_*`, `pmpro_*`, and Freemius actions are dependency APIs, not MasterStudy domain contracts, though MasterStudy may trigger them.

## 5. Core filter hooks

Filters return the first argument (the default) unless stated otherwise. Callback priorities and accepted argument counts must match the installed call site.

| Filter family | Default / return expectation |
|---|---|
| `masterstudy_lms_plugin_addons` | `array`; return addon objects to register. |
| `stm_lms_post_types_array`, `stm_lms_taxonomies`, `masterstudy_woo_post_types` | arrays; return valid registration definitions. |
| `stm_lms_course_price`, `stm_lms_get_sale_price`, `masterstudy_lms_subscription_price` | numeric/string price; preserve currency semantics. |
| `stm_lms_has_course_access`, `stm_lms_accept_order`, `stm_lms_before_change_course_status` | boolean/status gate; return the final decision/value. |
| `stm_lms_filter_courses`, `stm_lms_archive_filter_args`, `stm_lms_sorting_args` | query/argument arrays. |
| `masterstudy_lms_course_hydrate`, `masterstudy_lms_lesson_hydrate`, `masterstudy_lms_question_hydrate`, `masterstudy_lms_popular_course_hydrate` | hydrated arrays/objects; preserve required fields. |
| `masterstudy_lms_course_player_data`, `masterstudy_course_player_*_data` | player data arrays; return serializable data. |
| `masterstudy_lms_lesson_types`, `masterstudy_lms_lesson_video_types`, `masterstudy_lms_lesson_audio_types` | arrays of supported type identifiers. |
| `masterstudy_lms_lesson_validation_rules`, `masterstudy_lms_question_validation_rules` | validation-rule arrays. |
| `masterstudy_lms_lesson_fields_meta_mapping` | field-to-meta map. |
| `stm_lms_course_custom_fields`, `stm_lms_course_builder_custom_fields`, `masterstudy_lms_lesson_custom_fields`, `stm_lms_quiz_custom_fields` | custom-field definitions. |
| `stm_lms_cart_items_fields`, `stm_lms_order_details`, `stm_lms_user_orders` | arrays; add fields without removing existing fields. |
| `masterstudy_lms_checkout_providers`, `stm_lms_payment_supports`, gateway `*_enabled`, `*_supports`, `*_title`, `*_icon`, `*_method_description` | provider/gateway arrays, booleans, or display strings. |
| `stm_lms_gateway_*` | gateway capability/config value; return the same type. |
| `stm_lms_course_tabs`, `stm_lms_menu_items`, `stm_lms_sorted_menu`, `stm_lms_sorted_student_menu` | menu/tab arrays. |
| `stm_lms_template_file`, `stm_lms_template_name`, `stm_lms_{$template_name}` | template path/name or rendered-template data; return a valid replacement. |
| `stm_lms_allowed_html`, `stm_lms_pro_allowed_html`, `stm_lms_safe_output_content` | KSES allowlist/safe content; do not return untrusted raw HTML. |
| `masterstudy_lms_social_login_providers`, `masterstudy_lms_google_meet_settings` | provider/settings arrays. |
| `stm_lms_allow_group_manage`, `stm_lms_enterprise_price` | boolean gate or numeric price. |
| `masterstudy_lms_is_subscription_plan_recurring`, `masterstudy_lms_subscription_plan_billing_cycles_limit` | boolean/int derived from plan. |

Appendix B contains the exhaustive source-discovered MasterStudy filter-name index. Many legacy `stm_lms_*` filters have no PHPDoc and are intentionally documented by their first argument and call site; do not alter array shape without checking that caller.

## 6. Safe callable surface

Use these stable-looking seams first, with feature detection:

```php
if ( class_exists( 'STM_LMS_Options' ) ) {
    $value = STM_LMS_Options::get_option( 'course_tab_reviews', true );
}

if ( function_exists( 'stm_lms_has_course_access' ) ) {
    $allowed = (bool) stm_lms_has_course_access( $course_id, get_current_user_id() );
}
```

Recommended callable categories are `STM_LMS_Options::get_option()`, the public `stm_lms_*` cart/order/access/price helpers, repository methods in `MasterStudy\\Lms\\*Repository` classes, and WordPress APIs for posts, users, taxonomies, REST, cron, and metadata. Treat concrete repository constructors and legacy globals as implementation-sensitive; check `method_exists()` and the installed signature before calling. Never call private/protected methods, write directly to Pro tables, or depend on a template-local variable.

## 7. Extension rules and verification

Load an extension on `masterstudy_lms_plugin_loaded` (or `plugins_loaded` with a later priority), declare dependencies, and use namespaced/prefixed identifiers. Sanitize request data, verify nonces/capabilities, validate IDs and ownership, escape output, and avoid loading assets globally. Test free-only, Pro-disabled, addon-disabled, logged-out, logged-in, failed-payment, refund, and cron paths.

The inventories below were generated by searching PHP `do_action()` and `apply_filters()` calls in both installed plugin directories. Third-party hooks are intentionally excluded from the MasterStudy-owned lists.

## Appendix A — exhaustive owned action names

`MSLMS_ZOOM_admin_submenu_pages`, `masterstudy_account_sidebar`, `masterstudy_add_shortcode_memberships_page`, `masterstudy_after_account`, `masterstudy_after_certificates_grid`, `masterstudy_before_account`, `masterstudy_before_delete_certificate`, `masterstudy_before_delete_certificate_category`, `masterstudy_before_delete_default_certificate`, `masterstudy_before_save_certificate`, `masterstudy_before_save_certificate_category`, `masterstudy_before_save_default_certificate`, `masterstudy_before_upload_certificate_images`, `masterstudy_gla_exception`, `masterstudy_group_course_button`, `masterstudy_group_course_modal`, `masterstudy_lms_admin_assignment_review`, `masterstudy_lms_after_add_to_cart`, `masterstudy_lms_after_delete_user_course`, `masterstudy_lms_before_add_to_cart`, `masterstudy_lms_before_delete_user_course`, `masterstudy_lms_before_subscription_column_updated`, `masterstudy_lms_before_subscription_status_updated`, `masterstudy_lms_course_coming_soon_before_save`, `masterstudy_lms_course_player_register_assets`, `masterstudy_lms_course_player_update_user_current_lesson`, `masterstudy_lms_course_price_updated`, `masterstudy_lms_course_saved`, `masterstudy_lms_course_update_access`, `masterstudy_lms_course_video_saved`, `masterstudy_lms_curriculum_material_before_delete`, `masterstudy_lms_curriculum_material_created`, `masterstudy_lms_curriculum_material_updated`, `masterstudy_lms_custom_fields_updated`, `masterstudy_lms_delete_students_demo_mode`, `masterstudy_lms_gateway_subscription_cancelled`, `masterstudy_lms_order_completed`, `masterstudy_lms_plugin_loaded`, `masterstudy_lms_save_assignment`, `masterstudy_lms_save_lesson`, `masterstudy_lms_save_quiz`, `masterstudy_lms_subscription_activated`, `masterstudy_lms_subscription_cancelled`, `masterstudy_lms_subscription_created`, `masterstudy_lms_subscription_expired`, `masterstudy_lms_subscription_expires_soon`, `masterstudy_lms_subscription_payment_completed`, `masterstudy_lms_subscription_payment_failed`, `masterstudy_lms_subscription_payment_method_updated`, `masterstudy_lms_subscription_payment_refunded`, `masterstudy_lms_subscription_payment_succeeded`, `masterstudy_lms_subscription_reactivated`, `masterstudy_lms_subscription_refunded`, `masterstudy_lms_subscription_suspended`, `masterstudy_lms_subscription_updated`, `masterstudy_lms_user_quiz_added`, `masterstudy_lms_user_quiz_deleted`, `masterstudy_plugin_student_course_completion`, `masterstudy_point_system`, `masterstudy_prerequisite_button`, `masterstudy_show_analytics_templates`, `stm_generate_theme_styles`, `stm_import_end`, `stm_import_post_meta`, `stm_import_start`, `stm_import_term_meta`, `stm_lms_adding_enterprice_groups`, `stm_lms_admin_after_wrapper_start`, `stm_lms_after_assignment`, `stm_lms_after_groups_end`, `stm_lms_after_user_register`, `stm_lms_after_wishlist_list`, `stm_lms_announcement_ready_to_send`, `stm_lms_archive_card_price`, `stm_lms_assignment_`, `stm_lms_assignment_before_drafting`, `stm_lms_before_button_mixed`, `stm_lms_before_item_lesson_start`, `stm_lms_before_item_template_start`, `stm_lms_before_profile_buttons_all`, `stm_lms_before_send_chat_message`, `stm_lms_before_wishlist_list`, `stm_lms_certificate_generated`, `stm_lms_change_bundle_status`, `stm_lms_change_course_status`, `stm_lms_course_rejected`, `stm_lms_courses_have_posts`, `stm_lms_create_order_line_item`, `stm_lms_custom_content_for_single_course`, `stm_lms_delete_bundle`, `stm_lms_delete_from_cart`, `stm_lms_enqueue_login_script`, `stm_lms_enqueue_register_script`, `stm_lms_google_classroom_course_imported`, `stm_lms_group_updated`, `stm_lms_instructor_courses_end`, `stm_lms_lesson_passed`, `stm_lms_lesson_started`, `stm_lms_login_end`, `stm_lms_media_library_delete_image`, `stm_lms_media_library_upload_image`, `stm_lms_nuxy_repeater_upload_file`, `stm_lms_order_accepted`, `stm_lms_order_remove`, `stm_lms_pages_generated`, `stm_lms_payout_settings_save`, `stm_lms_progress_updated`, `stm_lms_purchase_action_done`, `stm_lms_quiz_`, `stm_lms_reschedule_all_expiration_reminders`, `stm_lms_save_bundle`, `stm_lms_saved_bundle`, `stm_lms_score_charge_{$action_id}`, `stm_lms_single_bundle_start`, `stm_lms_single_course_start`, `stm_lms_template_main`, `stm_lms_template_main_after`, `stm_lms_upload_files`, `stm_lms_user_course_added_`, `stm_lms_user_float_menu_before`, `stm_lms_user_registered`, `stm_lms_woocommerce_order_approved`, `stm_lms_woocommerce_order_cancelled`, `stm_wp_import_after_insert_attachment`, `stm_wp_import_before_fetch_attachment`, `stm_wp_import_insert_comment`, `stm_wp_import_insert_post`, `stm_wp_import_insert_term`, `stm_wp_import_insert_term_failed`, `stm_wp_import_post_exists`, `stm_wp_import_set_post_terms`, `stm_wp_import_update_nav_menu`, `stm_wpcfto_single_field_before_start`, `stm_zoom_after_create_meeting`, `stm_zoom_after_update_meeting`

## Appendix B — exhaustive owned filter-name index

The complete source index (198 names, including dynamic-hook patterns) is:

`MSLMS_ZOOM_single_zoom_template_shortcode`, `MSLMS_ZOOM_template_pathes`, `masterstudy_account_menu_section_labels`, `masterstudy_account_student_courses_per_page`, `masterstudy_account_user_wishlist_per_page`, `masterstudy_add_analytics_link`, `masterstudy_add_grades_link`, `masterstudy_authorization_demo_login`, `masterstudy_course_page_header`, `masterstudy_course_player_assignment_data`, `masterstudy_course_player_lesson_google_data`, `masterstudy_course_player_lesson_stream_data`, `masterstudy_group_courses_modal_data`, `masterstudy_lms_add_to_cart_response`, `masterstudy_lms_admin_react_allowed_app_slugs`, `masterstudy_lms_admin_react_app_settings_by_slug`, `masterstudy_lms_admin_react_instructor_app_slugs`, `masterstudy_lms_admin_submenu_items`, `masterstudy_lms_analytics_allowed_sort_columns`, `masterstudy_lms_audio_allowed`, `masterstudy_lms_audio_lesson_course_settings_fields`, `masterstudy_lms_certificate_fields_data`, `masterstudy_lms_checkout_providers`, `masterstudy_lms_coupon_cookie_expires`, `masterstudy_lms_course_builder_custom_fields`, `masterstudy_lms_course_completed_message`, `masterstudy_lms_course_custom_fields`, `masterstudy_lms_course_guest_trial_enabled`, `masterstudy_lms_course_hydrate`, `masterstudy_lms_course_not_completed_message`, `masterstudy_lms_course_player_complete_button_class`, `masterstudy_lms_course_player_data`, `masterstudy_lms_favicon_url`, `masterstudy_lms_fill_gap_question_output_data`, `masterstudy_lms_google_meet_settings`, `masterstudy_lms_instructor_react_menu_items`, `masterstudy_lms_is_subscription_plan_recurring`, `masterstudy_lms_lesson_audio_sources`, `masterstudy_lms_lesson_audio_sources_arr`, `masterstudy_lms_lesson_audio_types`, `masterstudy_lms_lesson_curriculum_data`, `masterstudy_lms_lesson_custom_fields`, `masterstudy_lms_lesson_fields_meta_mapping`, `masterstudy_lms_lesson_hydrate`, `masterstudy_lms_lesson_types`, `masterstudy_lms_lesson_validation_rules`, `masterstudy_lms_lesson_video_sources`, `masterstudy_lms_lesson_video_types`, `masterstudy_lms_map_api_data`, `masterstudy_lms_plugin_addons`, `masterstudy_lms_popular_course_hydrate`, `masterstudy_lms_question_hydrate`, `masterstudy_lms_question_validation_rules`, `masterstudy_lms_quiz_custom_fields`, `masterstudy_lms_social_login_providers`, `masterstudy_lms_subscription_before_cancel`, `masterstudy_lms_subscription_plan_billing_cycles_limit`, `masterstudy_lms_subscription_price`, `masterstudy_lms_timezones`, `masterstudy_lms_vuejs_disabled_pages`, `masterstudy_membership_modal_data`, `masterstudy_payment_increment`, `masterstudy_paystack_amount_multiplier`, `masterstudy_woo_post_types`, `stm_admin_notice_rate_`, `stm_admin_notices_data`, `stm_autocomplete_terms`, `stm_certificates_field`, `stm_certificates_fields`, `stm_import_allow_create_users`, `stm_import_allow_fetch_attachments`, `stm_import_attachment_size_limit`, `stm_import_post_meta_key`, `stm_import_term_meta_key`, `stm_listing_query_delete`, `stm_listing_query_select`, `stm_listing_query_update`, `stm_lms_accept_order`, `stm_lms_add_to_cart_r`, `stm_lms_allow_group_manage`, `stm_lms_allowed_html`, `stm_lms_archive_filter_args`, `stm_lms_archive_filter_content`, `stm_lms_available_addons`, `stm_lms_before_button_stop`, `stm_lms_before_change_course_status`, `stm_lms_bundle_image_url`, `stm_lms_buy_button_auth`, `stm_lms_cart_items_fields`, `stm_lms_co_instructor_avatar`, `stm_lms_co_instructor_login`, `stm_lms_course_item_content`, `stm_lms_course_passed_items`, `stm_lms_course_price`, `stm_lms_course_tabs`, `stm_lms_courses_page`, `stm_lms_courses_search_endpoint_post_types`, `stm_lms_current_user_data`, `stm_lms_custom_routes_config`, `stm_lms_default_featured_quota`, `stm_lms_delete_from_cart_filter`, `stm_lms_display_points`, `stm_lms_email_manager_emails`, `stm_lms_email_manager_settings`, `stm_lms_enable_add_course`, `stm_lms_enqueue_bootstrap`, `stm_lms_enterprise_price`, `stm_lms_extra_user_fields`, `stm_lms_featured_teacher_image_{$instructor}`, `stm_lms_filter_courses`, `stm_lms_filter_output`, `stm_lms_float_menu_enabled`, `stm_lms_float_menu_placed_items`, `stm_lms_form_builder_available_fields`, `stm_lms_gateway_enabled`, `stm_lms_gateway_icon`, `stm_lms_gateway_method_description`, `stm_lms_gateway_method_title`, `stm_lms_gateway_supports`, `stm_lms_gateway_title`, `stm_lms_get_course_price_in_meta`, `stm_lms_get_sale_price`, `stm_lms_get_user_courses_filter`, `stm_lms_get_vc_img`, `stm_lms_gmt_offset`, `stm_lms_group_concat_max_len`, `stm_lms_has_course_access`, `stm_lms_header_messages_counter`, `stm_lms_instructors_page`, `stm_lms_is_udemy_course`, `stm_lms_item_url_quiz_ended`, `stm_lms_live_stream_allowed`, `stm_lms_locate_vc_element`, `stm_lms_login`, `stm_lms_main_settings_fields`, `stm_lms_menu_items`, `stm_lms_order_details`, `stm_lms_payment_supports`, `stm_lms_payout_author_fee`, `stm_lms_payout_methods`, `stm_lms_paypal_enabled`, `stm_lms_paypal_sandbox`, `stm_lms_paypal_supports`, `stm_lms_paypal_verifying_webhooks`, `stm_lms_post_types_array`, `stm_lms_prev_status`, `stm_lms_pro_addons_enabled_`, `stm_lms_pro_allowed_html`, `stm_lms_pro_show_button`, `stm_lms_profile_form_default_fields_info`, `stm_lms_purchase_done`, `stm_lms_rating_user_fields`, `stm_lms_safe_output_content`, `stm_lms_sale_price_meta`, `stm_lms_scorm_allowed_files_ext`, `stm_lms_send_admin_course_notice`, `stm_lms_settings_api_sanitized_fields_`, `stm_lms_settings_menu_items`, `stm_lms_show_item_content`, `stm_lms_show_question_sidebar`, `stm_lms_show_social_login`, `stm_lms_single_item_cart_title`, `stm_lms_sorted_menu`, `stm_lms_sorted_student_menu`, `stm_lms_sorting_args`, `stm_lms_stop_item_output`, `stm_lms_taxonomies`, `stm_lms_template_file`, `stm_lms_template_name`, `stm_lms_update_user_avatar`, `stm_lms_update_user_cover`, `stm_lms_user_additional_fields`, `stm_lms_user_orders`, `stm_lms_wrapper_classes`, `stm_lms_{$template_name}`, `stm_nav_menu_item_additional_fields`, `stm_paypal_return_url`, `stm_theme_demo_layout`, `stm_wp_import_attachment_url`, `stm_wp_import_categories`, `stm_wp_import_existing_post`, `stm_wp_import_post_comments`, `stm_wp_import_post_data_processed`, `stm_wp_import_post_data_raw`, `stm_wp_import_post_meta`, `stm_wp_import_post_terms`, `stm_wp_import_posts`, `stm_wp_import_tags`, `stm_wp_import_term_meta`, `stm_wp_import_terms`, `stm_wpcfto_autocomplete_{$name}`, `stm_wpcfto_autocomplete_{$name}_output`, `stm_wpcfto_boxes`, `stm_wpcfto_fields`, `stm_wpcfto_filter_output`, `stm_wpcfto_ms_icons`, `stm_wpcfto_single_field_classes`, `stm_wpcfto_term_meta_fields`.

For maintenance, regenerate it with:

```bash
rg -o --glob '*.php' "apply_filters\\s*\\(\\s*['\"](masterstudy|stm_lms|stm_|MSLMS)[^'\"]*" \
  /opt/lampp/htdocs/plugin-dev/wp-content/plugins/masterstudy-lms-learning-management-system \
  /opt/lampp/htdocs/plugin-dev/wp-content/plugins/masterstudy-lms-learning-management-system-pro \
  | sed -E "s/.*apply_filters\\s*\\(\\s*['\"]//" | sort -u
```

This deliberate regeneration command is included because this plugin’s installed source exposes a large, frequently changing legacy filter surface; source line locations are the authoritative parameter/default contract.

## 8. Prepexpert parent-child quiz investigation (2026-09-02)

This installation does not use MasterStudy's native quiz model for the affected feature. The affected exams are AYS Quiz Maker records in the prefixed `aysquiz_quizes` table, rendered by the `[ays_quiz id="..."]` shortcode. MasterStudy supplies the account/dashboard shell and menu hooks; it does not own the quiz purchase or attempt record.

### Actual runtime path

1. `prep-expert-exam-papers.php` loads the parent-child module, live-class module, and `quiz/class-quiz-parent-extension.php`.
2. `Prep_Expert_Live_Class_Parent_Extension::add_child_dropdown_to_checkout_fields()` adds `billing_enrolled_child_user_id` only when the logged-in user has active children.
3. `save_child_id_to_order()` / its fallback writes `_enrolled_child_user_id` only if the submitted field is present and the current user owns that child.
4. `Prep_Expert_Quiz_Parent_Extension::enrol_child_quizzes()` reads the order customer as parent, reads `_enrolled_child_user_id`, finds AYS quizzes by decoding each quiz's `options['woocommerce_product']`, and stores quiz IDs in the selected child's `_enrolled_quiz_ids` user meta.
5. The quiz list is emitted only when `Prep_Expert_Live_Class_Parent_Extension::render_parent_child_dashboard()` calls `render_dashboard()`; it is not a standalone account page or MasterStudy quiz route.
6. The Start Exam link points to `home_url('/') . '?quiz_id=N'`. The quiz extension appends `[ays_quiz id="N"]` through `the_content`, but only when the current user's `_enrolled_quiz_ids` contains that ID.

### Why the issue persists

| Finding | Why it breaks the requirement | Evidence / required check |
|---|---|---|
| The feature relies on `_enrolled_child_user_id` being present on every order | If the checkout field is absent, renamed by the checkout implementation, or the order was created before the fallback ran, the quiz callback safely returns and no child receives the quiz | For both order IDs inspect `_enrolled_child_user_id`, `_customer_user`, status, and line-item product IDs in `wp_postmeta` / WooCommerce order storage |
| Enrollment is asynchronous and historically only status-driven | A paid order can have a different gateway transition path; a callback added later cannot repair an order unless that order is explicitly replayed | Compare order status/timestamps with callback logs; manually invoke the enrollment routine against each affected order during diagnosis |
| Quiz-to-product discovery scans every quiz and assumes one scalar option | AYS can store JSON with missing/empty/non-scalar values, and a product association mismatch produces an empty quiz ID list even though the order is valid | Decode and log only quiz ID/product ID pairs; compare each purchased product ID with `options['woocommerce_product']` |
| The diagnostic logger is dead code | `parent-child/parent-child.php::prep_expert_parent_child_write_log()` returns immediately before creating/writing the log file, so earlier debugging could not identify which stage failed | Remove the unconditional return only as an approved diagnostic/code change, then replay one order and inspect the exact stages |
| Child dashboard selection and quiz launch are separate concerns | Parent selection uses `?child_id=`, but the quiz launch uses only `?quiz_id=`. The quiz page has no selected-child context and must authorize the logged-in child from child meta | Test parent dashboard per child, then test the same quiz URL while logged into each child; never infer child identity from the parent's browser selection |
| AYS WooCommerce gating may still replace the quiz body | The AYS public renderer checks its WooCommerce integration settings and can replace the quiz content with its own purchase result. A custom `woocommerce_customer_bought_product` filter is not proof that the installed AYS WooCommerce integration accepts child access | Inspect the installed AYS WooCommerce addon callback and its final access check; run a child request and record rendered HTML/response, not only the URL |
| The root route is theme/content dependent | `the_content` runs only when the root request resolves to a renderable WordPress page. If Hello Elementor uses a template path that bypasses `the_content`, the shortcode filter never runs | Browser/network test the exact root request and temporarily log whether `render_quiz_root_route()` is entered; prefer a dedicated WordPress quiz page containing a shortcode |

### Correct repair sequence

Do not merge parent quiz meta into child meta and do not grant all quizzes bought by the parent. The safe repair is:

1. Add one reusable, idempotent order-assignment service. It must validate order existence, paid/allowed status, parent ID, child ID, active parent-owned relationship, line-item product IDs, and AYS table existence before writing.
2. Persist `_enrolled_child_user_id` through the canonical WooCommerce checkout order object and verify it after save. Register the same assignment service on payment completion and the actual paid status hooks used by the gateway.
3. Normalize AYS quiz options defensively and query only matching product associations. Store integer quiz IDs with `array_unique`; never overwrite another assignment.
4. Add a one-time admin/CLI repair command for existing orders rather than relying on page-load backfills. It must report order ID, child ID, product IDs, matched quiz IDs, and a redacted failure reason.
5. Create a dedicated published WordPress page, for example `/student-quizzes/`, with a quiz dashboard shortcode. Link Start Exam to that page with `quiz_id`; render the shortcode there and let the current logged-in child be the only identity used for authorization.
6. Verify AYS's own WooCommerce access gate. Either use its supported access filter/API or disable the AYS purchase gate for these exams and enforce access in the custom renderer. Rendering a shortcode alone is insufficient if AYS replaces it with a purchase message.
7. Add stage diagnostics (without email, password, payment secrets, or raw sensitive values), replay two real orders for two different children, and verify database meta plus browser output.

### Minimum acceptance matrix

| Scenario | Expected result |
|---|---|
| Parent buys quiz product for child A | Only child A gets that quiz ID in `_enrolled_quiz_ids` |
| Parent buys the same/different quiz product for child B | Only child B gets the corresponding quiz ID; child A's list is unchanged |
| Child A opens child B's quiz URL | No quiz is rendered and no AYS attempt can start |
| Authorized child opens own quiz URL | The AYS quiz UI renders and can submit an attempt under that child user ID |
| Order has no child metadata or inactive relationship | No enrollment occurs; a diagnostic reason is recorded |
| Parent opens dashboard and switches children | Each selected child shows only that child's quiz list and reports |

PHP lint and `git diff --check` validate syntax/whitespace only. They do not validate WooCommerce order metadata, AYS access callbacks, browser routing, quiz submission, database writes, or sibling isolation; those require runtime tests with two real child accounts and two real orders.
