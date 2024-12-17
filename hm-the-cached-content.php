<?php
/**
 * Plugin Name: The_Cached_Content()
 * Plugin Description: Provides a template function for fragment-caching the_content() without breaking styles.
 * Plugin Author: Human Made
 */

namespace The_Cached_Content {
	use WP_Block_Type_Registry;
	use WP_Block_Type;
    use WP_Dependencies;

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

	/**
	 * Helper function to calculate which registered dependencies should be cached
	 * for potential later restoration.
	 *
	 * @param \_WP_Dependencies $original_registry Original-state dependencies registry.
	 * @param \_WP_Dependencies $new_registry      Freshly created dependencies registry.
	 * @return array [ 'registered' => \_WP_Dependency[], 'queued' => string[] ] Dependencies to cache.
	 */
	function diff_dependency_registries( \WP_Dependencies $original_registry, \WP_Dependencies $new_registry ) : array {
		$dependencies = array_diff_key( $new_registry->registered, $original_registry->registered );
		$queued = $new_registry->queue ?? [];
		foreach ( $queued as $script_handle ) {
			// Also store all enqueued deps which were previously registered
			// to enable recreating the whole registered script list if needed.
			$dependencies[ $script_handle ] = $new_registry->registered[ $script_handle ];
		}
		return [
			'dependencies' => $dependencies,
			'queued'       => $queued,
		];
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
			$existing_scripts = $GLOBALS['wp_scripts'];
			$GLOBALS['wp_scripts'] = $new_scripts = new WP_Scripts();
			$existing_styles = $GLOBALS['wp_styles'];
			$GLOBALS['wp_styles'] = $new_styles = new WP_Styles();

			// Render content. Will trigger script enqueues within WP_Block::render.
			ob_start();
			the_content( $more_link_text, $strip_teaser );
			$the_content = (string) ob_get_clean();

			// Calculate all newly-registered or enqueued scripts.
			[
				'dependencies' => $registered_scripts,
				'queued'       => $queued_scripts
			] = The_Cached_Content\diff_dependency_registries( $existing_scripts, $new_scripts );
			[
				'dependencies' => $registered_styles,
				'queued'       => $queued_styles,
			] = The_Cached_Content\diff_dependency_registries( $existing_styles, $new_styles );

			$data = [
				'the_content'    => $the_content,
				'scripts'        => $registered_scripts,
				'queued_scripts' => $queued_scripts,
				'styles'         => $registered_styles,
				'queued_styles'  => $queued_styles,
			];

			set_transient( $cache_key, $data, $expiry );

			// Restore cached globals.
			$GLOBALS['wp_scripts'] = $existing_scripts;
			$GLOBALS['wp_styles'] = $existing_styles;

			echo '<pre>LIVE content</pre>';
		} else {
			echo '<pre>CACHED content</pre>';
		}

		foreach ( $data['scripts'] as $script_handle => $dependency ) {
			$GLOBALS['wp_scripts']->registered[ $script_handle ] = $dependency;
		}
		$GLOBALS['wp_scripts']->queue = $data['queued_scripts'];
		foreach ( $data['styles'] as $style_handle => $dependency ) {
			$GLOBALS['wp_styles']->registered[ $style_handle ] = $dependency;
		}
		$GLOBALS['wp_styles']->queue = $data['queued_styles'];

		// echo '<small><pre>';
		// print_r( array_merge( $data, [
		// 	'the_content' => substr( esc_html( trim( str_replace( "\n", ' ', $data['the_content'] ) ) ), 0, 150 ) . '...',
		// ] ) );
		// echo '</pre></small>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $data['the_content'];
	}
}
