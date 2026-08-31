<?php
/**
 * Exam papers shortcode plugin class.
 *
 * @package PrepExpertExamPapers
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('Prep_Expert_Exam_Papers_Plugin')) {

	/**
	 * Shortcode renderer for exam paper cards.
	 */
	final class Prep_Expert_Exam_Papers_Plugin {

		/**
		 * Plugin version.
		 *
		 * @var string
		 */
		const VERSION = '1.0.0';

		/**
		 * Shortcode tag.
		 *
		 * @var string
		 */
		const SHORTCODE_TAG = 'exam_papers';

		/**
		 * Stylesheet relative path.
		 *
		 * @var string
		 */
		const STYLE_HANDLE = 'prep-expert-exam-papers';

		/**
		 * Script handle.
		 *
		 * @var string
		 */
		const SCRIPT_HANDLE = 'prep-expert-exam-papers-gallery';

		/**
		 * Cached purchase lookups for the current request.
		 *
		 * @var array<string,bool>
		 */
		private $purchase_cache = array();

		/**
		 * Singleton instance.
		 *
		 * @var Prep_Expert_Exam_Papers_Plugin|null
		 */
		private static $instance = null;

		/**
		 * Get the singleton instance.
		 *
		 * @return Prep_Expert_Exam_Papers_Plugin
		 */
		public static function instance() {
			if (null === self::$instance) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register hooks.
		 */
		private function __construct() {
			add_shortcode(self::SHORTCODE_TAG, array($this, 'render_shortcode'));
		}

		/**
		 * Render the shortcode output.
		 *
		 * Supported optional shortcode attribute:
		 * - post_id: Render a specific exam paper post instead of the current post.
		 *
		 * @param array<string,mixed> $atts Shortcode attributes.
		 * @param string|null         $content Unused shortcode content.
		 * @param string              $shortcode_tag Shortcode tag name.
		 * @return string
		 */
		public function render_shortcode($atts = array(), $content = null, $shortcode_tag = '') {
			unset($content, $shortcode_tag);

			$atts = shortcode_atts(
				array(
					'post_id' => 0,
				),
				is_array($atts) ? $atts : array(),
				self::SHORTCODE_TAG
			);

			$post_id = absint($atts['post_id']);
			if ($post_id <= 0) {
				$post_id = get_the_ID();
			}

			if ($post_id <= 0) {
				return $this->render_notice(__('No exam paper post was found.', 'prep-expert-exam-papers'));
			}

			if (!function_exists('get_field')) {
				return $this->render_notice(__('Advanced Custom Fields is required to display exam papers.', 'prep-expert-exam-papers'));
			}

			$linked_product_id = $this->get_linked_product_id(get_field('linked_product', $post_id));
			$has_purchased     = $this->user_has_purchased_linked_product($linked_product_id);
			$rows              = get_field('exam_papers', $post_id);

			if (!is_array($rows) || empty($rows)) {
				return $this->render_notice(__('No exam papers are available for this post.', 'prep-expert-exam-papers'));
			}

			$free_rows    = array();
			$premium_rows = array();
			$serial_no    = 1;

			foreach ($rows as $row) {
				if (!is_array($row)) {
					continue;
				}

				$row['serial_no'] = $serial_no;
				$access = isset($row['access']) ? strtolower(sanitize_key((string) $row['access'])) : '';

				if ('premium' === $access) {
					$premium_rows[] = $row;
					$serial_no++;
					continue;
				}

				$free_rows[] = $row;
				$serial_no++;
			}

			$linked_product = $this->get_wc_product($linked_product_id);
			$this->enqueue_assets();

			ob_start();
			?>
			<div class="pex-exam-papers">
				<?php
				echo $this->render_product_preview($linked_product, $linked_product_id, $post_id);

				echo $this->render_section(
					__('Free Papers', 'prep-expert-exam-papers'),
					$free_rows,
					$has_purchased,
					$linked_product_id,
					'free'
				);

				echo $this->render_section(
					__('Premium Papers', 'prep-expert-exam-papers'),
					$premium_rows,
					$has_purchased,
					$linked_product_id,
					'premium'
				);
				?>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		/**
		 * Enqueue frontend assets for the shortcode output.
		 */
		private function enqueue_assets() {
			$css_path = plugin_dir_path(PREP_EXPERT_EXAM_PAPERS_FILE) . 'assets/css/prep-expert-exam-papers.css';
			$css_url  = plugin_dir_url(PREP_EXPERT_EXAM_PAPERS_FILE) . 'assets/css/prep-expert-exam-papers.css';
			$version  = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;

			wp_enqueue_style(self::STYLE_HANDLE, $css_url, array(), $version);

			$js_path = plugin_dir_path(PREP_EXPERT_EXAM_PAPERS_FILE) . 'assets/js/prep-expert-exam-papers.js';
			$js_url  = plugin_dir_url(PREP_EXPERT_EXAM_PAPERS_FILE) . 'assets/js/prep-expert-exam-papers.js';
			$js_version = file_exists($js_path) ? (string) filemtime($js_path) : self::VERSION;

			wp_enqueue_script(self::SCRIPT_HANDLE, $js_url, array(), $js_version, true);
		}

		/**
		 * Render a section table for one access level.
		 *
		 * @param string                $heading Table heading.
		 * @param array<int,array<mixed>> $rows Row data.
		 * @param bool                  $has_purchased Whether the linked product has been purchased.
		 * @param int                  $linked_product_id Linked WooCommerce product ID.
		 * @param string               $access_level Access level slug.
		 * @return string
		 */
		private function render_section($heading, array $rows, $has_purchased, $linked_product_id, $access_level) {
			ob_start();
			?>
			<section class="pex-exam-papers__section">
				<h2 class="pex-exam-papers__title"><?php echo esc_html($heading); ?></h2>
				<div class="pex-exam-papers__table-wrap">
					<?php if (empty($rows)) : ?>
						<div class="pex-exam-papers__empty"><?php echo esc_html__('No papers found in this category.', 'prep-expert-exam-papers'); ?></div>
					<?php else : ?>
						<div class="pex-exam-papers__table">
							<div class="pex-exam-papers__table-head">
								<div class="pex-exam-papers__head-cell"><?php echo esc_html__('S.no.', 'prep-expert-exam-papers'); ?></div>
								<div class="pex-exam-papers__head-cell"><?php echo esc_html__('Paper Title', 'prep-expert-exam-papers'); ?></div>
								<div class="pex-exam-papers__head-cell"><?php echo esc_html__('Question Paper', 'prep-expert-exam-papers'); ?></div>
								<div class="pex-exam-papers__head-cell"><?php echo esc_html__('Answers', 'prep-expert-exam-papers'); ?></div>
								<div class="pex-exam-papers__head-cell"><?php echo esc_html__('Mark Scheme', 'prep-expert-exam-papers'); ?></div>
							</div>

							<?php
			foreach ($rows as $row) :
								if (!is_array($row)) {
									continue;
								}

								$serial_no      = isset($row['serial_no']) ? absint($row['serial_no']) : 0;
								$title           = isset($row['title']) ? sanitize_text_field((string) $row['title']) : '';
								$paper_url       = $this->resolve_file_url(isset($row['paper']) ? $row['paper'] : '');
								$answer_url      = $this->resolve_file_url(isset($row['answer']) ? $row['answer'] : '');
								$mark_scheme_url = $this->resolve_file_url(isset($row['mark_scheme']) ? $row['mark_scheme'] : '');
								$row_access      = isset($row['access']) ? strtolower(sanitize_key((string) $row['access'])) : '';

								$answer_is_locked = ('premium' === $row_access && false === $has_purchased);
								$mark_is_locked   = false === $has_purchased;

								if ('free' === $row_access) {
									$answer_button_label = __('Free Answers', 'prep-expert-exam-papers');
									$mark_button_label   = __('Unlock', 'prep-expert-exam-papers');
								} else {
									$answer_button_label = __('Unlock', 'prep-expert-exam-papers');
									$mark_button_label   = __('Unlock', 'prep-expert-exam-papers');
								}

								if (true === $has_purchased) {
									if ('free' === $row_access) {
										$answer_button_label = __('Answers', 'prep-expert-exam-papers');
									} else {
										$answer_button_label = __('Answers', 'prep-expert-exam-papers');
									}
									$mark_button_label = __('Mark Scheme', 'prep-expert-exam-papers');
								}

								$paper_button = $this->render_button(
									__('Download', 'prep-expert-exam-papers'),
									$paper_url,
									false,
									'free'
								);

								$answer_button = $answer_is_locked
									? $this->render_button(
										$answer_button_label,
										$this->get_checkout_add_to_cart_url($linked_product_id),
										true,
										$row_access
									)
									: $this->render_button(
										$answer_button_label,
										$answer_url,
										false,
										$row_access
									);

								$mark_button = $mark_is_locked
									? $this->render_button(
										$mark_button_label,
										$this->get_checkout_add_to_cart_url($linked_product_id),
										true,
										$row_access
									)
									: $this->render_button(
										$mark_button_label,
										$mark_scheme_url,
										false,
										$row_access
									);
								?>
								<div class="pex-exam-papers__row">
									<div class="pex-exam-papers__cell pex-exam-papers__cell--index" data-label="<?php echo esc_attr__('S.no.', 'prep-expert-exam-papers'); ?>">
										<span class="pex-exam-papers__index"><?php echo esc_html((string) $serial_no); ?></span>
									</div>
									<div class="pex-exam-papers__cell pex-exam-papers__cell--title" data-label="<?php echo esc_attr__('Paper Title', 'prep-expert-exam-papers'); ?>">
										<span class="pex-exam-papers__paper-title"><?php echo esc_html($title); ?></span>
									</div>
									<div class="pex-exam-papers__cell" data-label="<?php echo esc_attr__('Question Paper', 'prep-expert-exam-papers'); ?>">
										<div class="pex-exam-papers__actions">
											<?php echo $paper_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
									<div class="pex-exam-papers__cell" data-label="<?php echo esc_attr__('Answers', 'prep-expert-exam-papers'); ?>">
										<div class="pex-exam-papers__actions">
											<?php echo $answer_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
									<div class="pex-exam-papers__cell" data-label="<?php echo esc_attr__('Mark Scheme', 'prep-expert-exam-papers'); ?>">
										<div class="pex-exam-papers__actions">
											<?php echo $mark_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										</div>
									</div>
								</div>
								<?php
							endforeach;
							?>
						</div>
					<?php endif; ?>
				</div>
			</section>
			<?php

			return (string) ob_get_clean();
		}

		/**
		 * Render a button or locked CTA.
		 *
		 * @param string $label Button label.
		 * @param string $url Button URL.
		 * @param bool   $locked Whether the button is locked.
		 * @param string $access_level Current row access level.
		 * @return string
		 */
		private function render_button($label, $url, $locked, $access_level) {
			$label       = sanitize_text_field((string) $label);
			$access_level = sanitize_key((string) $access_level);
			$url         = is_string($url) ? trim($url) : '';

			if (empty($url)) {
				return sprintf(
					'<span class="pex-exam-papers__btn %1$s pex-exam-papers__btn--disabled" aria-disabled="true">%2$s<span>%3$s</span></span>',
					esc_attr($locked ? 'pex-exam-papers__btn--locked' : 'pex-exam-papers__btn--unlocked'),
					$locked ? $this->get_lock_svg() : '',
					esc_html($label)
				);
			}

			$classes = array(
				'pex-exam-papers__btn',
			);

			if (true === $locked) {
				$classes[] = 'pex-exam-papers__btn--locked';
			} elseif ('free' === $access_level) {
				$classes[] = 'pex-exam-papers__btn--free';
			} else {
				$classes[] = 'pex-exam-papers__btn--unlocked';
			}

			$svg = true === $locked ? $this->get_lock_svg() : '';

			return sprintf(
				'<a class="%1$s" href="%2$s">%3$s<span>%4$s</span></a>',
				esc_attr(implode(' ', $classes)),
				esc_url($url),
				$svg,
				esc_html($label)
			);
		}

		/**
		 * Render a short notice box.
		 *
		 * @param string $message Message to display.
		 * @return string
		 */
		private function render_notice($message) {
			return sprintf(
				'<div class="pex-exam-papers__empty">%s</div>',
				esc_html($message)
			);
		}

		/**
		 * Render the linked product purchase preview block.
		 *
		 * @param WC_Product|null $product Linked WooCommerce product object.
		 * @param int             $product_id Linked product ID.
		 * @param int             $post_id Exam paper post ID.
		 * @return string
		 */
		private function render_product_preview($product, $product_id, $post_id) {
			$product_id = absint($product_id);
			$post_id     = absint($post_id);

			if (!class_exists('WC_Product') || !($product instanceof WC_Product)) {
				if ($product_id > 0) {
					return $this->render_notice(__('Linked WooCommerce product is unavailable.', 'prep-expert-exam-papers'));
				}

				return '';
			}

			$product_link = get_permalink($product_id);
			if (empty($product_link)) {
				$product_link = $product->get_permalink();
			}

			$post_title = '';
			if ($post_id > 0) {
				$post_title = get_the_title($post_id);
			}
			if (empty($post_title)) {
				$post_title = $product->get_name();
			}

			$product_content = '';
			if ($post_id > 0) {
				$product_content = wp_kses_post(apply_filters('the_content', get_post_field('post_content', $post_id)));
			}

			$featured_image_id = $post_id > 0 ? absint(get_post_thumbnail_id($post_id)) : 0;
			$featured_alt      = '';
			$featured_src      = '';

			if ($featured_image_id > 0) {
				$featured_src = wp_get_attachment_image_url($featured_image_id, 'large');
				$featured_alt = get_post_meta($featured_image_id, '_wp_attachment_image_alt', true);
				if (empty($featured_alt)) {
					$featured_alt = get_the_title($post_id);
				}
			}

			$gallery_items = $this->get_gallery_items($post_id);
			if (empty($featured_src) && !empty($gallery_items)) {
				$featured_src = isset($gallery_items[0]['full']) ? $gallery_items[0]['full'] : '';
				$featured_alt = isset($gallery_items[0]['alt']) ? $gallery_items[0]['alt'] : $post_title;
			}

			$product_price = $this->format_product_price_html($product->get_price_html());
			$add_to_cart_url = $product->add_to_cart_url();
			if (empty($add_to_cart_url)) {
				$add_to_cart_url = $product_link;
			}

			$button_text = $product->add_to_cart_text();
			if (empty($button_text)) {
				$button_text = __('Add to basket', 'prep-expert-exam-papers');
			}

			$breadcrumb_items = array();
			$breadcrumb_items[] = array(
				'label' => __('Home', 'prep-expert-exam-papers'),
				'url'   => home_url('/'),
			);

			$term_link = '';
			$term_label = '';
			if ($post_id > 0) {
				$terms = wp_get_post_terms($post_id, 'academic-level', array('number' => 1));
				if (!is_wp_error($terms) && !empty($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
					$term_label = $terms[0]->name;
					$term_link  = get_term_link($terms[0]);
				}
			}

			if (!empty($term_label) && !is_wp_error($term_link)) {
				$breadcrumb_items[] = array(
					'label' => $term_label,
					'url'   => $term_link,
				);
			} else {
				$archive_link = get_post_type_archive_link('exam-paper');
				if (!empty($archive_link) && !is_wp_error($archive_link)) {
					$breadcrumb_items[] = array(
						'label' => __('Exam Papers', 'prep-expert-exam-papers'),
						'url'   => $archive_link,
					);
				}
			}

			ob_start();
			?>
			<div class="pex-exam-papers__product">
				<div class="pex-exam-papers__gallery" data-pex-exam-papers-gallery data-original-src="<?php echo esc_attr($featured_src); ?>" data-original-alt="<?php echo esc_attr($featured_alt); ?>">
					<div class="pex-exam-papers__thumbs" aria-label="<?php echo esc_attr__('Gallery thumbnails', 'prep-expert-exam-papers'); ?>">
						<?php if (!empty($featured_src)) : ?>
							<button
								type="button"
								class="pex-exam-papers__thumb pex-exam-papers__thumb--active-thumb"
								data-full-src="<?php echo esc_url($featured_src); ?>"
								data-full-alt="<?php echo esc_attr($featured_alt); ?>"
								aria-label="<?php echo esc_attr__('Featured image', 'prep-expert-exam-papers'); ?>"
								aria-pressed="true"
							>
								<img src="<?php echo esc_url($featured_src); ?>" alt="<?php echo esc_attr($featured_alt); ?>" loading="lazy" />
							</button>
						<?php endif; ?>

						<?php if (!empty($gallery_items)) : ?>
							<?php foreach ($gallery_items as $index => $gallery_item) : ?>
								<button
									type="button"
									class="pex-exam-papers__thumb"
									data-full-src="<?php echo esc_url($gallery_item['full']); ?>"
									data-full-alt="<?php echo esc_attr($gallery_item['alt']); ?>"
									aria-label="<?php echo esc_attr($gallery_item['label']); ?>"
									aria-pressed="false"
								>
									<?php if (!empty($gallery_item['thumb'])) : ?>
										<img src="<?php echo esc_url($gallery_item['thumb']); ?>" alt="<?php echo esc_attr($gallery_item['alt']); ?>" loading="lazy" />
									<?php endif; ?>
								</button>
							<?php endforeach; ?>
						<?php else : ?>
							<!-- Gallery thumbnails are intentionally disabled until a post gallery is available. -->
						<?php endif; ?>
					</div>

					<div class="pex-exam-papers__main-media">
						<?php if (!empty($featured_src)) : ?>
							<img
								class="pex-exam-papers__hero-image"
								src="<?php echo esc_url($featured_src); ?>"
								alt="<?php echo esc_attr($featured_alt); ?>"
							/>
						<?php else : ?>
							<div class="pex-exam-papers__thumb pex-exam-papers__thumb--placeholder" aria-hidden="true"></div>
						<?php endif; ?>
					</div>
				</div>

				<div class="pex-exam-papers__product-content">
					<nav class="pex-exam-papers__breadcrumb" aria-label="<?php echo esc_attr__('Breadcrumb', 'prep-expert-exam-papers'); ?>">
						<?php foreach ($breadcrumb_items as $index => $breadcrumb_item) : ?>
							<?php if ($index > 0) : ?>
								<span>/</span>
							<?php endif; ?>
							<?php if (!empty($breadcrumb_item['url'])) : ?>
								<a href="<?php echo esc_url($breadcrumb_item['url']); ?>">
									<?php echo esc_html($breadcrumb_item['label']); ?>
								</a>
							<?php else : ?>
								<span class="pex-exam-papers__breadcrumb-current"><?php echo esc_html($breadcrumb_item['label']); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
						<span>/</span>
						<span class="pex-exam-papers__breadcrumb-current"><?php echo esc_html($post_title); ?></span>
					</nav>

					<h2 class="pex-exam-papers__product-title"><?php echo esc_html($post_title); ?></h2>

					<div class="pex-exam-papers__students">
						<svg class="pex-exam-papers__students-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
							<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
							<circle cx="9" cy="7" r="4"></circle>
							<path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
							<path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
						</svg>
						<strong><?php echo esc_html__('8000+', 'prep-expert-exam-papers'); ?></strong>
						<span><?php echo esc_html__('Students', 'prep-expert-exam-papers'); ?></span>
					</div>

					<?php if (!empty($product_content)) : ?>
						<div class="pex-exam-papers__product-description"><?php echo wp_kses_post($product_content); ?></div>
					<?php endif; ?>

					<div class="pex-exam-papers__buy-row">
						<div class="pex-exam-papers__price"><?php echo wp_kses_post($product_price); ?></div>

						<div class="pex-exam-papers__product-cta">
							<a class="pex-exam-papers__btn pex-exam-papers__btn--free" href="<?php echo esc_url($add_to_cart_url); ?>">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false">
									<circle cx="9" cy="21" r="1"></circle>
									<circle cx="20" cy="21" r="1"></circle>
									<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
								</svg>
								<?php echo esc_html($button_text); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		/**
		 * Build gallery items for the purchase preview.
		 *
		 * @param int $post_id Exam paper post ID.
		 * @return array<int,array<string,string>>
		 */
		private function get_gallery_items($post_id) {
			$post_id = absint($post_id);
			if ($post_id <= 0 || !function_exists('get_field')) {
				return array();
			}

			$gallery = get_field('paper_image_gallery', $post_id);
			if (!is_array($gallery) || empty($gallery)) {
				return array();
			}

			$items = array();
			foreach ($gallery as $image) {
				$image_id = 0;
				$full     = '';
				$thumb    = '';
				$alt      = '';

				if (is_array($image)) {
					if (isset($image['ID'])) {
						$image_id = absint($image['ID']);
					} elseif (isset($image['id'])) {
						$image_id = absint($image['id']);
					}
				} elseif (is_object($image) && isset($image->ID)) {
					$image_id = absint($image->ID);
				} elseif (is_numeric($image)) {
					$image_id = absint($image);
				}

				if (is_array($image) && isset($image['url'])) {
					$full = esc_url_raw((string) $image['url']);
					if (isset($image['sizes']['thumbnail'])) {
						$thumb = esc_url_raw((string) $image['sizes']['thumbnail']);
					} elseif (isset($image['sizes']['medium'])) {
						$thumb = esc_url_raw((string) $image['sizes']['medium']);
					}

					if (isset($image['alt'])) {
						$alt = sanitize_text_field((string) $image['alt']);
					}
				}

				if ($image_id > 0) {
					$full  = $full ? $full : wp_get_attachment_image_url($image_id, 'large');
					$thumb = $thumb ? $thumb : wp_get_attachment_image_url($image_id, 'thumbnail');
					$alt   = $alt ? $alt : (string) get_post_meta($image_id, '_wp_attachment_image_alt', true);
					if (empty($alt)) {
						$alt = get_the_title($image_id);
					}
				}

				if (empty($full)) {
					continue;
				}

				$items[] = array(
					'full'  => esc_url_raw($full),
					'thumb' => $thumb ? esc_url_raw($thumb) : esc_url_raw($full),
					'alt'   => sanitize_text_field($alt),
					'label' => sprintf(__('Gallery image %d', 'prep-expert-exam-papers'), count($items) + 1),
				);
			}

			return $items;
		}

		/**
		 * Convert the linked product ACF value into a product ID.
		 *
		 * @param mixed $linked_product ACF post object, ID, or similar value.
		 * @return int
		 */
		private function get_linked_product_id($linked_product) {
			if (is_object($linked_product) && isset($linked_product->ID)) {
				return absint($linked_product->ID);
			}

			if (is_array($linked_product) && isset($linked_product['ID'])) {
				return absint($linked_product['ID']);
			}

			return absint($linked_product);
		}

		/**
		 * Load a WooCommerce product object by ID.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return WC_Product|null
		 */
		private function get_wc_product($product_id) {
			$product_id = absint($product_id);
			if ($product_id <= 0 || !function_exists('wc_get_product')) {
				return null;
			}

			$product = wc_get_product($product_id);
			if (!class_exists('WC_Product') || !($product instanceof WC_Product)) {
				return null;
			}

			return $product;
		}

		/**
		 * Normalize WooCommerce recurring price labels.
		 *
		 * Converts phrases like "every month" and "every year" to "/month" and "/year".
		 *
		 * @param string $price_html Raw WooCommerce price HTML.
		 * @return string
		 */
		private function format_product_price_html($price_html) {
			if (!is_string($price_html) || '' === trim($price_html)) {
				return '';
			}

			$normalized = str_replace(
				array('&nbsp;', '&#160;', "\xc2\xa0"),
				' ',
				$price_html
			);
			$normalized = trim($normalized);

			$plain_text = html_entity_decode(wp_strip_all_tags($normalized), ENT_QUOTES, get_bloginfo('charset'));
			$plain_text = preg_replace('/\s+/u', ' ', (string) $plain_text);
			$plain_text = trim((string) $plain_text);

			if (!is_string($plain_text) || '' === $plain_text) {
				return '';
			}

			$pattern = '/^(.*?)(?:\s*(?:every|per|each)\s*(?:1\s*)?(month|year)|\s*(monthly|yearly|annually))\s*$/i';
			if (preg_match($pattern, $plain_text, $matches)) {
				$amount = isset($matches[1]) ? trim((string) $matches[1]) : '';
				$period = '';

				if (!empty($matches[2])) {
					$period = strtolower((string) $matches[2]);
				} elseif (!empty($matches[3])) {
					$period = 'year' === strtolower((string) $matches[3]) ? 'year' : 'month';
					if ('monthly' === strtolower((string) $matches[3])) {
						$period = 'month';
					}
					if ('yearly' === strtolower((string) $matches[3]) || 'annually' === strtolower((string) $matches[3])) {
						$period = 'year';
					}
				}

				if ('' !== $amount && in_array($period, array('month', 'year'), true)) {
					return sprintf(
						'<span class="pex-exam-papers__price-amount">%1$s</span><small class="pex-exam-papers__price-period">/%2$s</small>',
						esc_html($amount),
						esc_html($period)
					);
				}
			}

			$updated = preg_replace(
				array(
					'/\bevery(?:\s|<[^>]+>)+(?:1\s*)?month\b/i',
					'/\bper(?:\s|<[^>]+>)+(?:1\s*)?month\b/i',
					'/\beach(?:\s|<[^>]+>)+(?:1\s*)?month\b/i',
					'/\bmonthly\b/i',
				),
				'<small class="pex-exam-papers__price-period">/month</small>',
				$normalized
			);

			if (is_string($updated) && false === stripos($updated, 'every month')) {
				$normalized = $updated;
			}

			$updated = preg_replace(
				array(
					'/\bevery(?:\s|<[^>]+>)+(?:1\s*)?year\b/i',
					'/\bper(?:\s|<[^>]+>)+(?:1\s*)?year\b/i',
					'/\beach(?:\s|<[^>]+>)+(?:1\s*)?year\b/i',
					'/\byearly\b/i',
					'/\bannually\b/i',
				),
				'<small class="pex-exam-papers__price-period">/year</small>',
				$normalized
			);

			if (is_string($updated)) {
				$normalized = $updated;
			}

			return $normalized;
		}

		/**
		 * Determine whether the current logged-in user bought the linked product.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return bool
		 */
		private function user_has_purchased_linked_product($product_id) {
			$product_id = absint($product_id);
			if ($product_id <= 0) {
				return false;
			}

			if (!is_user_logged_in()) {
				return false;
			}

			if (!function_exists('wc_customer_bought_product')) {
				return false;
			}

			$current_user = wp_get_current_user();
			if (!($current_user instanceof WP_User) || empty($current_user->ID) || empty($current_user->user_email)) {
				return false;
			}

			$user_id = absint($current_user->ID);
			$email   = sanitize_email($current_user->user_email);
			if (empty($email)) {
				return false;
			}

			$cache_key = 'pex_exam_papers_v2_' . $user_id . '_' . $product_id;

			if (array_key_exists($cache_key, $this->purchase_cache)) {
				return (bool) $this->purchase_cache[ $cache_key ];
			}

			$cached = wp_cache_get($cache_key, 'pex_exam_papers');
			if (false !== $cached) {
				$this->purchase_cache[ $cache_key ] = (bool) $cached;
				return (bool) $cached;
			}

			$has_purchased = (bool) wc_customer_bought_product($email, $user_id, $product_id);

			// A parent places the order, but the selected child owns the access.
			// Check only that child's assigned parent orders; never inherit the
			// parent's unrelated purchases or another child's purchase.
			if ( ! $has_purchased && class_exists( 'Prep_Expert_Parent_Child_Database' ) && function_exists( 'wc_get_orders' ) ) {
				$parent_id = absint( Prep_Expert_Parent_Child_Database::get_parent_by_child( $user_id ) );
				if ( $parent_id && $parent_id !== $user_id ) {
					$parent_orders = wc_get_orders(
						array(
							'customer_id' => $parent_id,
							'status'      => array( 'processing', 'completed' ),
							'limit'       => -1,
							'return'      => 'objects',
						)
					);

					foreach ( $parent_orders as $parent_order ) {
						if ( $user_id !== absint( $parent_order->get_meta( '_enrolled_child_user_id' ) ) ) {
							continue;
						}

						foreach ( $parent_order->get_items() as $item ) {
							if ( $product_id === absint( $item->get_product_id() ) ) {
								$has_purchased = true;
								break 2;
							}
						}
					}
				}
			}

			$this->purchase_cache[ $cache_key ] = $has_purchased;
			wp_cache_set($cache_key, $has_purchased, 'pex_exam_papers', HOUR_IN_SECONDS);

			return $has_purchased;
		}

		/**
		 * Build the checkout add-to-cart URL for locked items.
		 *
		 * @param int $product_id WooCommerce product ID.
		 * @return string
		 */
		private function get_checkout_add_to_cart_url($product_id) {
			$product_id = absint($product_id);
			if ($product_id <= 0) {
				return '';
			}

			$checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/checkout/');

			return add_query_arg('add-to-cart', $product_id, $checkout_url);
		}

		/**
		 * Return inline SVG used for locked actions.
		 *
		 * @return string
		 */
		private function get_lock_svg() {
			return '<svg class="pex-exam-papers__lock-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 10V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M6.5 10H17.5C18.3284 10 19 10.6716 19 11.5V19.5C19 20.3284 18.3284 21 17.5 21H6.5C5.67157 21 5 20.3284 5 19.5V11.5C5 10.6716 5.67157 10 6.5 10Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 14V17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
		}

		/**
		 * Resolve an ACF file field value to a usable URL.
		 *
		 * @param mixed $file_value ACF file field value.
		 * @return string
		 */
		private function resolve_file_url($file_value) {
			if (is_array($file_value) && !empty($file_value['url'])) {
				return esc_url_raw((string) $file_value['url']);
			}

			if (is_object($file_value) && isset($file_value->url)) {
				return esc_url_raw((string) $file_value->url);
			}

			if (is_numeric($file_value)) {
				$attachment_url = wp_get_attachment_url(absint($file_value));
				return $attachment_url ? esc_url_raw($attachment_url) : '';
			}

			if (is_string($file_value) && '' !== trim($file_value)) {
				return esc_url_raw(trim($file_value));
			}

			return '';
		}
	}
}
