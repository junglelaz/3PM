<?php
/**
 * Plugin Name: 3PM – Featured Image Toggle (Per Post)
 * Description: Adds a per-post checkbox to hide the featured image on that post’s single page. Works with most themes (filters the_post_thumbnail output).
 * Version:     1.1.1
 * Author:      3rd Person Music
 * License:     GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

class ThreePM_Featured_Image_Toggle {

	const META_KEY = '_threepm_hide_featured_image';

	public function __construct() {
		add_action('add_meta_boxes', [$this, 'add_meta_box']);
		add_action('save_post', [$this, 'save_meta']);
		add_filter('post_thumbnail_html', [$this, 'maybe_hide_featured_image'], 10, 5);
		add_filter('render_block', [$this, 'maybe_hide_featured_image_block'], 10, 3);
	}

	public function add_meta_box() {
		add_meta_box(
			'threepm_hide_featured_image',
			__('Featured Image Display', 'threepm'),
			[$this, 'render_meta_box'],
			array('post','page'),
			'side',
			'default'
		);
	}

	public function render_meta_box($post) {
		$value = get_post_meta($post->ID, self::META_KEY, true);
		wp_nonce_field('threepm_hide_featured_image_save', 'threepm_hide_featured_image_nonce');
		?>
		<p>
			<label>
				<input type="checkbox" name="threepm_hide_featured_image" value="1" <?php checked($value, '1'); ?> />
				<?php esc_html_e('Hide featured image on this post’s page', 'threepm'); ?>
			</label>
		</p>
		<p style="color:#666; margin-top:8px;">
			<small><?php esc_html_e('Default: visible. Check to hide on the single post view only.', 'threepm'); ?></small>
		</p>
		<?php
	}

	public function save_meta($post_id) {
		if (
			!isset($_POST['threepm_hide_featured_image_nonce']) ||
			!wp_verify_nonce($_POST['threepm_hide_featured_image_nonce'], 'threepm_hide_featured_image_save')
		) { return; }

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }

		$post_type = get_post_type($post_id);
		if (!in_array($post_type, array('post','page')) || !current_user_can('edit_post', $post_id)) { return; }

		$value = isset($_POST['threepm_hide_featured_image']) ? '1' : '0';
		update_post_meta($post_id, self::META_KEY, $value);
	}

	/**
	 * Only hide the featured image when the thumbnail being rendered belongs to
	 * the main queried object (the actual single Post/Page being viewed).
	 * This prevents hiding thumbnails used in sliders/widgets on the same page.
	 */
	public function maybe_hide_featured_image($html, $post_id, $thumb_id, $size, $attr) {
		if (is_admin()) return $html;

		// Only act on single Post/Page views
		if (!is_singular(array('post','page'))) return $html;

		$queried_id = get_queried_object_id();
		if (!$queried_id) return $html;

		// If the thumbnail is not for the main object, don't touch it
		if ((int)$post_id !== (int)$queried_id) return $html;

		$hide = get_post_meta($queried_id, self::META_KEY, true) === '1';
		return $hide ? '' : $html;
	}

	/**
	 * Catch the Featured Image block for the main queried object only.
	 */
	public function maybe_hide_featured_image_block($block_content, $block, $instance = null) {
		if (is_admin()) return $block_content;
		if (!is_singular(array('post','page'))) return $block_content;

		if (isset($block['blockName']) && $block['blockName'] === 'core/post-featured-image') {
			$queried_id = get_queried_object_id();
			$block_post_id = isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();
			if ($block_post_id !== (int) $queried_id) return $block_content;
			if ($queried_id && get_post_meta($queried_id, self::META_KEY, true) === '1') {
				return '';
			}
		}
		return $block_content;
	}
}

new ThreePM_Featured_Image_Toggle();
