<?php
/**
 * Plugin Name: 3PM – FileBird Doc Library (fbdl) Subfolder Browser
 * Description: Adds a subfolder browser and ensures the FileBird Document Library ([fbdl]) includes files from the selected folder AND all its subfolders.
 * Version:     1.2.0
 * Author:      3PM
 * Requires PHP: 7.2
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Taxonomy detection.
 * We try to detect FileBird's taxonomy slug automatically for maximum compatibility.
 */
function threepm_fb_taxonomy_slug(): string {
	static $slug = null;
	if ( $slug !== null ) return $slug;

	// Common constants some builds may expose
	if ( defined('FBV_TAXONOMY') && taxonomy_exists( FBV_TAXONOMY ) ) return $slug = FBV_TAXONOMY;
	if ( defined('FILEBIRD_TAXONOMY') && taxonomy_exists( FILEBIRD_TAXONOMY ) ) return $slug = FILEBIRD_TAXONOMY;

	// Usual suspects
	$candidates = array( 'filebird', 'njt_filebird', 'fbv', 'fbv_categories' );
	foreach ( $candidates as $c ) {
		if ( taxonomy_exists( $c ) ) { $slug = $c; return $slug; }
	}

	// Discover by scanning attachment taxonomies
	$taxes = get_object_taxonomies( 'attachment', 'objects' );
	if ( is_array( $taxes ) ) {
		foreach ( $taxes as $tax ) {
			if ( ! empty( $tax->hierarchical ) ) {
				$name = $tax->name;
				if ( stripos( $name, 'filebird' ) !== false || stripos( $name, 'fbv' ) !== false ) {
					$slug = $name;
					return $slug;
				}
			}
		}
	}

	// Fallback
	return $slug = 'filebird';
}

/**
 * Expand a list of folder IDs to include all descendant term IDs (plus self).
 */
function threepm_fb_expand_to_descendants( array $term_ids ): array {
	$slug = threepm_fb_taxonomy_slug();
	$seen = array();
	$out  = array();

	foreach ( $term_ids as $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) continue;

		$stack = array( $id );
		while ( $stack ) {
			$cur = array_pop( $stack );
			if ( isset( $seen[ $cur ] ) ) continue;
			$seen[ $cur ] = true;
			$out[] = $cur;

			$children = get_terms( array(
				'taxonomy'   => $slug,
				'hide_empty' => false,
				'parent'     => $cur,
				'fields'     => 'ids',
			) );
			if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
				foreach ( $children as $child_id ) {
					$stack[] = (int) $child_id;
				}
			}
		}
	}
	$out = array_values( array_unique( array_map( 'intval', $out ) ) );
	return $out;
}

/**
 * Normalize CSV -> int[] helper.
 */
function threepm_fb_parse_csv_ids( $csv ): array {
	if ( is_array( $csv ) ) {
		$flat = array();
		foreach ( $csv as $v ) {
			foreach ( explode( ',', (string) $v ) as $p ) {
				$p = trim( $p );
				if ( $p !== '' && is_numeric( $p ) ) $flat[] = (int) $p;
			}
		}
		return $flat;
	}
	$out = array();
	foreach ( explode( ',', (string) $csv ) as $part ) {
		$part = trim( $part );
		if ( $part !== '' && is_numeric( $part ) ) $out[] = (int) $part;
	}
	return $out;
}

/**
 * Build current URL with a new folder param (ID=0 removes it).
 * We support both ?filebird=ID and ?fbdl_folder=ID for flexibility.
 */
function threepm_fb_current_url_with_folder( int $term_id ): string {
	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
	$uri    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
	$base   = $scheme . '://' . $host . strtok( $uri, '?' );

	$args = $_GET;
	if ( $term_id > 0 ) {
		$args['filebird']   = $term_id;
		$args['fbdl_folder'] = $term_id;
	} else {
		unset( $args['filebird'], $args['fbdl_folder'] );
	}
	return esc_url_raw( add_query_arg( $args, $base ) );
}

/**
 * Get the "current" folder selection from URL or shortcode-provided defaults.
 */
function threepm_fb_get_current_folder_from_request(): int {
	foreach ( array('filebird','fbdl_folder','folder') as $key ) {
		if ( isset($_GET[$key]) && is_numeric($_GET[$key]) ) {
			return (int) $_GET[$key];
		}
	}
	return 0;
}

/**
 * PART A — Make the [fbdl] shortcode include descendants.
 * We hook into shortcode_atts_fbdl to expand its selected_folder CSV.
 * Also, when current="1", we override selected_folder with the clicked folder.
 */
add_action( 'init', function() {
	if ( shortcode_exists( 'fbdl' ) ) {
		add_filter( 'shortcode_atts_fbdl', function( $out, $pairs, $atts, $shortcode ) {
			$slug = threepm_fb_taxonomy_slug(); // ensure taxonomy is available

			$selected_csv = isset($out['selected_folder']) ? $out['selected_folder'] : '';
			$selected_ids = threepm_fb_parse_csv_ids( $selected_csv );

			// If current="1" or empty selection, pull from request (?filebird / ?fbdl_folder)
			$use_current = isset($out['current']) && (string)$out['current'] === '1';
			$req_id = threepm_fb_get_current_folder_from_request();

			if ( $use_current && $req_id > 0 ) {
				$selected_ids = array( $req_id );
				$out['selected_folder'] = (string) $req_id;
			} elseif ( empty($selected_ids) && $req_id > 0 ) {
				$selected_ids = array( $req_id );
				$out['selected_folder'] = (string) $req_id;
			}

			if ( ! empty( $selected_ids ) ) {
				$expanded = threepm_fb_expand_to_descendants( $selected_ids );
				$out['selected_folder'] = implode( ',', $expanded );
			}

			return $out;
		}, 10, 4 );
	}

	// Also support [filebird_document] just in case.
	if ( shortcode_exists( 'filebird_document' ) ) {
		add_filter( 'shortcode_atts_filebird_document', function( $out, $pairs, $atts, $shortcode ) {
			$req_id = threepm_fb_get_current_folder_from_request();
			if ( $req_id > 0 ) {
				$out['folder'] = (int) $req_id;
			}
			// If plugin supports CSV, expand too
			if ( isset($out['folder']) ) {
				$ids = threepm_fb_parse_csv_ids( $out['folder'] );
				if ( $ids ) {
					$expanded = threepm_fb_expand_to_descendants( $ids );
					$out['folder'] = implode( ',', $expanded );
				}
			}
			return $out;
		}, 10, 4 );
	}
}, 20 ); // after most plugins register shortcodes


/**
 * PART B — As a safety net, also widen any WP_Query tax_query on FileBird taxonomy.
 * This catches document queries that bypass shortcode_atts filtering.
 */
add_action( 'pre_get_posts', function( WP_Query $q ) {
	if ( is_admin() ) return;

	$slug = threepm_fb_taxonomy_slug();
	$tax_query = $q->get( 'tax_query' );
	if ( ! is_array( $tax_query ) ) return;

	$found = false;
	$term_ids = array();

	foreach ( $tax_query as $row ) {
		if ( is_array($row) && isset($row['taxonomy']) && $row['taxonomy'] === $slug && ! empty($row['terms']) ) {
			$found = true;
			$ids = is_array($row['terms']) ? $row['terms'] : array($row['terms']);
			foreach ( $ids as $id ) { if ( is_numeric($id) ) $term_ids[] = (int) $id; }
		}
	}

	if ( ! $found || empty($term_ids) ) return;

	$expanded = threepm_fb_expand_to_descendants( $term_ids );

	$new_tx = array(
		'taxonomy'         => $slug,
		'field'            => 'term_id',
		'terms'            => $expanded,
		'include_children' => false,
		'operator'         => 'IN',
	);

	// Remove existing rows for this taxonomy then add ours
	$tax_query = array_values( array_filter( $tax_query, function( $row ) use ( $slug ) {
		return ! ( is_array($row) && isset($row['taxonomy']) && $row['taxonomy'] === $slug );
	} ) );
	$tax_query[] = $new_tx;

	$q->set( 'tax_query', $tax_query );
}, 12 );


/**
 * PART C — Shortcode UI: [filebird_browser selected_folder="4,5,6" start="auto"]
 * - If a URL selection exists (?filebird / ?fbdl_folder), it wins.
 * - Else, if selected_folder is provided, start on its first ID.
 * - Else, start at first available top-level folder; if none, the first folder.
 */
add_shortcode( 'filebird_browser', function( $atts ) {
	$atts = shortcode_atts( array(
		'selected_folder' => '', // CSV accepted
		'class'           => '',
		'show_counts'     => '1',
	), $atts, 'filebird_browser' );

	$slug = threepm_fb_taxonomy_slug();
	$current_id = threepm_fb_get_current_folder_from_request();

	if ( $current_id <= 0 ) {
		// Fallback to first of selected_folder CSV
		$sel = threepm_fb_parse_csv_ids( $atts['selected_folder'] );
		if ( ! empty( $sel ) ) {
			$current_id = (int) $sel[0];
		}
	}

	// Last resort: pick first top-level; if none, pick any folder
	if ( $current_id <= 0 ) {
		$top = get_terms( array(
			'taxonomy'   => $slug,
			'hide_empty' => false,
			'parent'     => 0,
			'number'     => 1,
		) );
		if ( ! is_wp_error( $top ) && ! empty( $top ) ) {
			$current_id = (int) $top[0]->term_id;
		} else {
			$any = get_terms( array(
				'taxonomy'   => $slug,
				'hide_empty' => false,
				'number'     => 1,
			) );
			if ( ! is_wp_error( $any ) && ! empty( $any ) ) {
				$current_id = (int) $any[0]->term_id;
			}
		}
	}

	if ( $current_id <= 0 ) {
		return '<div class="filebird-browser notice">No FileBird folders found.</div>';
	}

	$current = get_term( $current_id, $slug );
	if ( ! $current || is_wp_error( $current ) ) {
		return '<div class="filebird-browser notice">Invalid FileBird folder.</div>';
	}

	ob_start();
	$classes = 'filebird-browser';
	if ( ! empty( $atts['class'] ) ) $classes .= ' ' . sanitize_html_class( $atts['class'] );

	echo '<div class="'. esc_attr( $classes ) .'">';
	echo threepm_fb_render_breadcrumbs( $current, $slug );

	$children = get_terms( array(
		'taxonomy'   => $slug,
		'hide_empty' => false,
		'parent'     => $current->term_id,
	) );

	if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
		echo '<ul class="filebird-subfolders">';
		foreach ( $children as $child ) {
			$url = threepm_fb_current_url_with_folder( (int) $child->term_id );
			echo '<li class="filebird-subfolder-item">';
			echo '<a href="'. esc_url( $url ) .'" class="filebird-subfolder-link">';
			echo '<span class="filebird-subfolder-name">'. esc_html( $child->name ) .'</span>';
			if ( $atts['show_counts'] === '1' ) {
				echo '<span class="filebird-subfolder-count">'. intval( $child->count ) .'</span>';
			}
			echo '</a>';
			echo '</li>';
		}
		echo '</ul>';
	} else {
		echo '<div class="filebird-no-subfolders">No subfolders.</div>';
	}

	echo '</div>';

	?>
	<style>
	.filebird-browser { margin: 0 0 1rem 0; }
	.filebird-breadcrumbs { display:flex; flex-wrap:wrap; gap:.4rem; font-size:14px; margin-bottom:.6rem; }
	.filebird-breadcrumbs a { text-decoration:none; }
	.filebird-subfolders { list-style:none; padding:0; margin:.5rem 0 0 0; display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:.5rem; }
	.filebird-subfolder-item { border:1px solid #e5e7eb; border-radius:10px; padding:.6rem .7rem; }
	.filebird-subfolder-link { display:flex; justify-content:space-between; align-items:center; text-decoration:none; }
	.filebird-subfolder-name { font-weight:600; }
	.filebird-subfolder-count { opacity:.7; font-size:12px; }
	</style>
	<?php

	return ob_get_clean();
} );

/**
 * Breadcrumbs builder (uses detected taxonomy).
 */
function threepm_fb_render_breadcrumbs( WP_Term $current, string $slug ): string {
	$trail = array( $current );
	$walker = $current;
	while ( $walker && $walker->parent ) {
		$walker = get_term( $walker->parent, $slug );
		if ( $walker && ! is_wp_error( $walker ) ) {
			$trail[] = $walker;
		} else {
			break;
		}
	}
	$trail = array_reverse( $trail );

	$parts = array();
	$root_link = threepm_fb_current_url_with_folder( 0 ); // clear param
	$parts[] = '<a href="'. esc_url( $root_link ) .'">All Folders</a>';

	foreach ( $trail as $term ) {
		$url = threepm_fb_current_url_with_folder( (int) $term->term_id );
		$parts[] = '<span class="sep">/</span><a href="'. esc_url( $url ) .'">'. esc_html( $term->name ) .'</a>';
	}
	return '<nav class="filebird-breadcrumbs">'. implode( ' ', $parts ) .'</nav>';
}
