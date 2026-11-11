<?php
/**
 * Plugin Name: 3PM – Featured Image Toggle (Per Post)
 * Description: Adds a per-post checkbox to hide the featured image on that post’s single page. Works with most themes (filters the_post_thumbnail output).
 * Version:     1.1.0
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
		add_filter('render_block', [$this, 'maybe_hide_featured_image_block'], 10, 2);
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
				<input type="checkbox" name="threepm_hide_featured_image" value="1"
