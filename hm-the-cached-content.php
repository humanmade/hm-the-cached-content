<?php
/**
 * Plugin Name: The_Cached_Content()
 * Plugin Description: Provides a template function for fragment-caching the_content() without breaking styles.
 * Plugin Author: Human Made
 */

namespace The_Cached_Content {
	use WP_Post;

	/** Connect namespace functions to actions and hooks. */
	function bootstrap() : void {
		// Hook into the save_post action.
		add_action( 'save_post', __NAMESPACE__ . '\\invalidate_post_cache', 10, 3 );
	}
	bootstrap();

	/**
	 * Generate a hashed cache key for a post's content.
	 *
	 * @param WP_Post|object|int $post           Optional. WP_Post instance or Post ID/object. Default null.
	 * @return string
	 */
	function cache_key( $post = null ) : string {
		$post_id = is_int( $post )
			? $post
			: ( get_post( $post )->ID ?? null );
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

	/**
	 * Invalidate the cache for a post when it is saved.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	function invalidate_post_cache( int $post_id, WP_Post $post, bool $update ) {
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
	 * Merge any new entries into the original registry.
	 *
	 * @param \WP_Dependencies  $dependencies Original WP_Dependencies registry.
	 * @param \_WP_Dependency[] $registered   Dependencies registered or enqueued during rendering.
	 * @param string[]          $queue        Content-enqueued dependencies.
	 * @return \WP_Dependencies Original registry with new entries injected.
	 */
	function update_dependency_registry( $dependencies, $registered, $queue ) {
		foreach ( $registered as $dependency_handle => $dependency ) {
			$dependencies->registered[ $dependency_handle ] = $dependency;
		}
		$dependencies->queue = array_unique( array_merge( $dependencies->queue, $queue ) );
		return $dependencies;
	}

	/**
	 * Helper function to calculate which registered dependencies should be cached
	 * for potential later restoration.
	 *
	 * @param \WP_Dependencies $original_registry Original-state dependencies registry.
	 * @param \WP_Dependencies $new_registry      Freshly created dependencies registry.
	 * @return array [ 'registered' => \_WP_Dependency[], 'queue' => string[] ] Dependencies to cache.
	 */
	function diff_dependency_registries( $original_registry, $new_registry ) : array {
		$dependencies = array_diff_key( $new_registry->registered, $original_registry->registered );
		$queue = $new_registry->queue ?? [];
		foreach ( $queue as $script_handle ) {
			// Also store all enqueued deps which were previously registered
			// to enable recreating the whole registered script list if needed.
			$dependencies[ $script_handle ] = $new_registry->registered[ $script_handle ];
		}
		return [
			'dependencies' => $dependencies,
			'queue'        => $queue,
		];
	}
}

// Global template function.
namespace {
	/**
	 * Displays the post content with a context-aware caching layer.
	 *
	 * This function should only be called within the loop.
	 *
	 * @param string             $more_link_text Optional. Content for when there is more text.
	 * @param bool               $strip_teaser   Optional. Strip teaser content before the more text. Default false.
	 * @param WP_Post|object|int $post           Optional. WP_Post instance or Post ID/object. Default null.
	 * @param int                $expiry         Optional. Expiry time (in seconds) before cache is invalidated. Default 5min.
	 */
	function the_cached_content( $more_link_text = null, $strip_teaser = false, $post = null, $expiry = MINUTE_IN_SECONDS * 5 ) {
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

			// Enqueue all stored styles so that inline stylesheets may be included
			// in the dependency diff. This normally happens in wp_footer, but we can
			// call it here because we're using a temporary WP_Styles which will be
			// replaced with the original registry after this.
			wp_enqueue_stored_styles();

			// Calculate all newly-registered or enqueued scripts.
			[
				'dependencies' => $registered_scripts,
				'queue'        => $queued_scripts
			] = The_Cached_Content\diff_dependency_registries( $existing_scripts, $new_scripts );
			[
				'dependencies' => $registered_styles,
				'queue'        => $queued_styles,
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
		}

		// Once we have cache data (or have computed what gets rendered during
		// the_content), ensure all of that data is in the global registries.
		$GLOBALS['wp_scripts'] = The_Cached_Content\update_dependency_registry(
			$GLOBALS['wp_scripts'],
			$data['scripts'] ?? [],
			$data['queued_scripts'] ?? []
		);
		$GLOBALS['wp_styles'] = The_Cached_Content\update_dependency_registry(
			$GLOBALS['wp_styles'],
			$data['styles'] ?? [],
			$data['queued_styles'] ?? []
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $data['the_content'];
	}
}
