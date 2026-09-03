<?php
/**
 * Plugin Name: Prep Expert Exam Papers
 * Description: Shortcode for rendering ACF exam papers with WooCommerce purchase-based access control.
 * Version: 1.2.5
 * Author: Prep Expert
 * Text Domain: prep-expert-exam-papers
 *
 * @package PrepExpertExamPapers
 */

if (!defined('ABSPATH')) {
	exit;
}

define('PREP_EXPERT_EXAM_PAPERS_FILE', __FILE__);
define('PREP_EXPERT_EXAM_PAPERS_DIR', __DIR__);

require_once __DIR__ . '/includes/class-prep-expert-exam-papers-plugin.php';

Prep_Expert_Exam_Papers_Plugin::instance();

require_once __DIR__ . '/parent-child/parent-child.php';
require_once __DIR__ . '/live-class/live-class.php';
require_once __DIR__ . '/quiz/class-quiz-parent-extension.php';
