<?php
/**
 * Plugin Name: The_Cached_Content()
 * Plugin Description: Provides a template function for fragment-caching the_content() without breaking styles.
 * Plugin Author: Human Made
 */

namespace The_Cached_Content {
	use WP_Block_Type_Registry;
	use WP_Block_Type;

	/**
	 * Get a block by name from the global registry.
	 *
	 * @param string $block_name Block type name.
	 * @return WP_Block_Type|null Block type object, or null if not registered.
	 */
	function get_block_type( string $block_name ) : ?WP_Block_Type {
		return WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
	}

	/**
	 * Recursively identify script and style handles in an array of parsed blocks.
	 *
	 * @param array[] $blocks Array of parse_blocks() output.
	 * @return array[] Array of `[ 'style' => string[], 'script' => string[] ]` handles.
	 */
	function identify_block_assets_recursive( array $blocks ) : array {
		$assets = [ 'style' => [], 'script' => [], 'script_module' => [] ];

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$block_type = get_block_type( $block['blockName'] );

				// Mirror the style handle processing in WP_Block::render.
				if ( ( ! empty( $block_type->script_handles ) ) ) {
					foreach ( $block_type->script_handles as $script_handle ) {
						$assets['script'][ $script_handle ] = true;
					}
				}

				if ( ! empty( $block_type->view_script_handles ) ) {
					foreach ( $block_type->view_script_handles as $view_script_handle ) {
						$assets['script'][ $view_script_handle ] = true;
					}
				}

				if ( ! empty( $block_type->view_script_module_ids ) ) {
					foreach ( $block_type->view_script_module_ids as $view_script_module_id ) {
						$assets['script_module'][ $view_script_module_id ] = true;
					}
				}

				if ( ( ! empty( $block_type->style_handles ) ) ) {
					foreach ( $block_type->style_handles as $style_handle ) {
						$assets['style'][ $style_handle ] = true;
					}
				}

				if ( ( ! empty( $block_type->view_style_handles ) ) ) {
					foreach ( $block_type->view_style_handles as $view_style_handle ) {
						$assets['style'][ $view_style_handle ] = true;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$assets = array_merge_recursive( $assets, identify_block_assets_recursive( $block['innerBlocks'] ) );
			}
		}

		return $assets;
	}

	/**
	 * Given a post's raw post_content, iterate through the included blocks to
	 * build a comprehensive list of associated style and script handles.
	 *
	 * @param string $content The raw post_content.
	 * @return array[] Array of `[ 'style' => string[], 'script' => string[] ]` handles.
	 */
	function identify_block_assets( string $content ) : array {
		$assets = identify_block_assets_recursive( parse_blocks( $content ) );
		return $assets;
	}

	/**
	 * Generate a hashed cache key for a post's content.
	 *
	 * @param string             $more_link_text Optional. Content for when there is more text.
	 * @param bool               $strip_teaser   Optional. Strip teaser content before the more text. Default false.
	 * @param WP_Post|object|int $post           Optional. WP_Post instance or Post ID/object. Default null.
	 * @return string
	 */
	function cache_key( $post = null ) : string {
		$post_id = is_int( $post ) ? $post : ( get_post( $post )->ID ?? null );
		return 'the_cached_content_' . md5( $post_id );
	}

	/**
	 * Flush the content cache for a post.
	 *
	 * @param WP_Post|object|int $post WP_Post instance or Post ID/object. Default null.
	 */
	function flush_the_cached_content( $post ) {
		delete_transient( cache_key( $post ) );
	}

	function invalidate_post_cache( int $post_id, \WP_Post $post, bool $update ) {
		// Check if this is an autosave or a revision, and bail if it is.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Ensure it's only triggered for published posts.
		if ( $post->post_status !== 'publish' ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Run your custom function.
		flush_the_cached_content( $post );
	}

	// Hook into the save_post action.
	add_action( 'save_post', __NAMESPACE__ . '\\invalidate_post_cache', 10, 3 );
}

// Global template function.
namespace {
	/**
	 * Displays the post content with a context-aware caching layer.
	 *
	 * @param string             $more_link_text Optional. Content for when there is more text.
	 * @param bool               $strip_teaser   Optional. Strip teaser content before the more text. Default false.
	 * @param WP_Post|object|int $post           Optional. WP_Post instance or Post ID/object. Default null.
	 * @param int                $expiry         Optional. Expiry time (in seconds) before cache is invalidated. Default 1min.
	 */
	function the_cached_content( $more_link_text = null, $strip_teaser = false, $post = null, $expiry = MINUTE_IN_SECONDS ) {
		$cache_key = The_Cached_Content\cache_key( $post );
		$data = get_transient( $cache_key );

		if ( empty( $data['the_content'] ) ) {
			global $wp_scripts;
			global $wp_styles;
			$known_scripts = $wp_scripts->queue ?? [];
			$known_styles = $wp_styles->queue ?? [];

			// Render content. Will trigger script enqueues within WP_Block::render.
			ob_start();
			the_content( $more_link_text, $strip_teaser );
			$the_content = (string) ob_get_clean();

			$content_scripts = array_values( array_diff( $wp_scripts->queue ?? [], $known_scripts ) );
			$content_styles = array_values( array_diff( $wp_styles->queue ?? [], $known_styles ) );

			echo '<small><pre>';
			print_r( wp_json_encode( [
				'known' => $known_scripts,
				'queue' => array_fill_keys( $wp_scripts->queue ?? [], 1 ),
			], JSON_PRETTY_PRINT ) );
			echo '</pre></small>';
			echo '<small><pre>';
			print_r( "\ndiff: " . wp_json_encode( $content_scripts ) );
			echo '</pre></small>';

			$data = [
				'the_content' => $the_content,
				'scripts'     => $content_scripts,
				'styles'      => $content_styles,
			];

			set_transient( $cache_key, $data, $expiry );
		} else {
			foreach ( $data['scripts'] as $script_handle ) {
				wp_enqueue_script( $script_handle );
			}
			foreach ( $data['styles'] as $style_handle ) {
				wp_enqueue_style( $style_handle );
			}
		}

		echo '<small><pre>';
		print_r( array_merge( $data, [
			'the_content' => substr( esc_html( trim( str_replace( "\n", ' ', $data['the_content'] ) ) ), 0, 150 ) . '...',
		] ) );
		echo '</pre></small>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $data['the_content'];
	}
}
