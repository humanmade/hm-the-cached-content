<?php
/**
 * Plugin Name: The_Cached_Content()
 * Plugin Description: Provides a template function for fragment-caching the_content() without breaking styles.
 * Author: Human Made
 * Version: 0.3.0
 * Author URI: https://humanmade.com/
 */

namespace The_Cached_Content {
	use WP_Post;
	use WP_Dependencies;

	const CACHE_VERSION = 2;

	/** Connect namespace functions to actions and hooks. */
	function bootstrap() : void {
		add_action( 'save_post', __NAMESPACE__ . '\\invalidate_post_cache', 10, 3 );
	}
	bootstrap();

	/**
	 * Generate a hashed cache key for a post's content.
	 *
	 * @param WP_Post|object|int $post Optional. WP_Post instance or Post ID/object. Default null.
	 * @return string
	 */
	function cache_key( $post = null ) : string {
		$post_id = is_int( $post )
			? $post
			: ( get_post( $post )->ID ?? null );

		return apply_filters( 'cached_content_cache_key', 'the_cached_content_' . md5( $post_id ), $post_id );
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
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post->post_status !== 'publish' ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		flush_the_cached_content( $post );
	}

	/**
	 * Snapshot the state of a dependencies registry prior to rendering.
	 *
	 * Captures the set of registered handles, the `extra` array on each dep
	 * (which is where `wp_add_inline_style()` data lives), and the current
	 * queue. A post-render diff uses this to identify exactly what the_content
	 * caused to be registered, extended, or enqueued.
	 *
	 * @param WP_Dependencies $deps Registry to snapshot.
	 * @return array
	 */
	function snapshot_dependencies( WP_Dependencies $deps ) : array {
		$extras = [];
		foreach ( $deps->registered as $handle => $dep ) {
			$extras[ $handle ] = isset( $dep->extra ) && is_array( $dep->extra )
				? $dep->extra
				: [];
		}
		return [
			'handles' => array_keys( $deps->registered ),
			'extras'  => $extras,
			'queue'   => is_array( $deps->queue ) ? $deps->queue : [],
		];
	}

	/**
	 * Diff a registry's current state against a prior snapshot.
	 *
	 * Returns three buckets:
	 *  - `registered`: wholly new deps registered during render.
	 *  - `extras`: new entries added to existing deps' `extra` arrays
	 *    (e.g. inline styles attached via `wp_add_inline_style` to a handle
	 *    that was pre-registered at `init`).
	 *  - `queue`: handles enqueued during render.
	 *
	 * @param WP_Dependencies $deps     Post-render registry.
	 * @param array           $snapshot Output of snapshot_dependencies().
	 * @return array
	 */
	function diff_against_snapshot( WP_Dependencies $deps, array $snapshot ) : array {
		$registered = [];
		$extras     = [];
		$known      = array_flip( $snapshot['handles'] ?? [] );

		foreach ( $deps->registered as $handle => $dep ) {
			if ( ! isset( $known[ $handle ] ) ) {
				$registered[ $handle ] = $dep;
				continue;
			}

			$before = $snapshot['extras'][ $handle ] ?? [];
			$after  = isset( $dep->extra ) && is_array( $dep->extra ) ? $dep->extra : [];
			$delta  = [];

			foreach ( $after as $key => $value ) {
				$prior = $before[ $key ] ?? null;
				if ( $value === $prior ) {
					continue;
				}
				if ( is_array( $value ) && is_array( $prior ) ) {
					$new_items = array_values( array_diff( $value, $prior ) );
					if ( $new_items ) {
						$delta[ $key ] = $new_items;
					}
				} elseif ( is_array( $value ) ) {
					$delta[ $key ] = array_values( $value );
				} else {
					$delta[ $key ] = $value;
				}
			}

			if ( $delta ) {
				$extras[ $handle ] = $delta;
			}
		}

		$queue = array_values( array_diff(
			is_array( $deps->queue ) ? $deps->queue : [],
			$snapshot['queue'] ?? []
		) );

		return [
			'registered' => $registered,
			'extras'     => $extras,
			'queue'      => $queue,
		];
	}

	/**
	 * Apply a cached diff to a live registry.
	 *
	 * - New registrations are added directly.
	 * - `extras` are *appended* to existing deps' `extra` arrays rather than
	 *   replacing them, so inline styles survive even if another request-time
	 *   code path has also attached data to the same handle.
	 * - Queue handles are merged in.
	 *
	 * @param WP_Dependencies $deps Live registry to modify.
	 * @param array           $diff Output of diff_against_snapshot().
	 * @return WP_Dependencies
	 */
	function replay_diff( WP_Dependencies $deps, array $diff ) : WP_Dependencies {
		foreach ( $diff['registered'] ?? [] as $handle => $dep ) {
			if ( ! ( $dep instanceof \_WP_Dependency ) ) {
				continue;
			}
			// If the handle was registered in the temp registry during render
			// but has since been registered on the real registry too (e.g.
			// `core-block-supports`, which core registers inside
			// `wp_enqueue_stored_styles` at `wp_enqueue_scripts` priority 10),
			// don't overwrite it. Merge the cached `extra` data onto the live
			// dep instead so both sets of inline CSS survive.
			if ( isset( $deps->registered[ $handle ] ) ) {
				$cached_extra = isset( $dep->extra ) && is_array( $dep->extra ) ? $dep->extra : [];
				if ( $cached_extra ) {
					$diff['extras'][ $handle ] = array_merge_recursive(
						$diff['extras'][ $handle ] ?? [],
						$cached_extra
					);
				}
				continue;
			}
			$deps->registered[ $handle ] = $dep;
		}

		foreach ( $diff['extras'] ?? [] as $handle => $delta ) {
			if ( ! isset( $deps->registered[ $handle ] ) ) {
				continue;
			}
			$dep = $deps->registered[ $handle ];
			if ( ! isset( $dep->extra ) || ! is_array( $dep->extra ) ) {
				$dep->extra = [];
			}
			foreach ( $delta as $key => $value ) {
				if ( is_array( $value ) ) {
					$existing = isset( $dep->extra[ $key ] ) && is_array( $dep->extra[ $key ] )
						? $dep->extra[ $key ]
						: [];
					$dep->extra[ $key ] = array_values( array_unique( array_merge( $existing, $value ) ) );
				} else {
					$dep->extra[ $key ] = $value;
				}
			}
		}

		if ( ! empty( $diff['queue'] ) ) {
			$deps->queue = array_values( array_unique( array_merge(
				is_array( $deps->queue ) ? $deps->queue : [],
				$diff['queue']
			) ) );
		}

		return $deps;
	}

	/**
	 * Produce a deep copy of a dependencies registry so that render-time
	 * mutations (e.g. `wp_add_inline_style` appending to a pre-registered
	 * handle's `extra['after']`) do not leak back to the real registry.
	 *
	 * @param WP_Dependencies $deps
	 * @return WP_Dependencies
	 */
	function deep_clone_dependencies( WP_Dependencies $deps ) : WP_Dependencies {
		return unserialize( serialize( $deps ) );
	}

	/**
	 * Apply the cached dependency diff now, or defer until `wp_enqueue_scripts`
	 * has fired if we're running earlier in the request.
	 *
	 * Deferring matters because callers like the early term-content render
	 * hooked to `pre_get_posts` run before core enqueues things like
	 * `global-styles` and `core-block-supports`. Merging directly in that
	 * window would prepend the cached handles to the style queue and flip
	 * the CSS cascade order at print time.
	 */
	function apply_or_defer_replay( array $data ) : void {
		$apply = function () use ( $data ) : void {
			$GLOBALS['wp_scripts'] = replay_diff( $GLOBALS['wp_scripts'], $data['scripts'] ?? [] );
			$GLOBALS['wp_styles']  = replay_diff( $GLOBALS['wp_styles'],  $data['styles'] ?? [] );

			$all_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
			foreach ( $all_blocks as $block_type ) {
				if ( ! array_key_exists( $block_type->name, $data['used_blocks'] ?? [] ) ) {
					continue;
				}
				if ( ! empty( $block_type->view_script_handles ) ) {
					foreach ( $block_type->view_script_handles as $handle ) {
						wp_enqueue_script( $handle );
					}
				}
				if ( ! empty( $block_type->style_handles ) ) {
					foreach ( $block_type->style_handles as $handle ) {
						wp_enqueue_style( $handle );
					}
				}
			}
		};

		if ( did_action( 'wp_enqueue_scripts' ) ) {
			$apply();
			return;
		}

		// Priority 100 runs after core-registered enqueue callbacks, so the
		// cached handles are appended to the queue in the same relative
		// position they would occupy during a non-cached render.
		add_action( 'wp_enqueue_scripts', $apply, 100 );
	}
}

// Global template function.
namespace {
	/**
	 * Display the post content with a context-aware caching layer.
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

		// Treat pre-v2 cache entries as misses so their stale format is not replayed.
		if ( ! empty( $data ) && ( $data['version'] ?? 0 ) !== The_Cached_Content\CACHE_VERSION ) {
			$data = false;
		}

		if ( empty( $data['the_content'] ) ) {
			// Deep-clone the real registries so that render-time calls like
			// wp_add_inline_style() on pre-registered handles succeed (they
			// require the handle to already exist in the current registry),
			// without mutating the real registry in place.
			$existing_scripts = $GLOBALS['wp_scripts'];
			$existing_styles  = $GLOBALS['wp_styles'];
			$GLOBALS['wp_scripts'] = The_Cached_Content\deep_clone_dependencies( $existing_scripts );
			$GLOBALS['wp_styles']  = The_Cached_Content\deep_clone_dependencies( $existing_styles );

			$scripts_snapshot = The_Cached_Content\snapshot_dependencies( $GLOBALS['wp_scripts'] );
			$styles_snapshot  = The_Cached_Content\snapshot_dependencies( $GLOBALS['wp_styles'] );

			// Track all blocks used so we can enqueue their assets manually.
			$used_blocks = [];
			$tracker = function ( $block_content, $block ) use ( &$used_blocks ) {
				if ( ! empty( $block['blockName'] ) ) {
					$used_blocks[ $block['blockName'] ] = true;
				}
				return $block_content;
			};
			add_filter( 'render_block', $tracker, 1, 2 );

			ob_start();
			the_content( $more_link_text, $strip_teaser );
			$the_content = (string) ob_get_clean();

			remove_filter( 'render_block', $tracker, 1 );

			// Flush any style-engine-stored block-support rules into the temp
			// registry so the compiled inline stylesheet is captured in the diff.
			wp_enqueue_stored_styles();

			$script_diff = The_Cached_Content\diff_against_snapshot( $GLOBALS['wp_scripts'], $scripts_snapshot );
			$style_diff  = The_Cached_Content\diff_against_snapshot( $GLOBALS['wp_styles'], $styles_snapshot );

			$data = [
				'version'     => The_Cached_Content\CACHE_VERSION,
				'the_content' => $the_content,
				'scripts'     => $script_diff,
				'styles'      => $style_diff,
				'used_blocks' => $used_blocks,
			];

			/**
			 * Modify the cached data used to reconstitute this content from cache.
			 *
			 * @param array $data Data to be cached.
			 */
			$data = apply_filters( 'cached_content_data', $data );

			set_transient( $cache_key, $data, $expiry );

			// Restore the real registries.
			$GLOBALS['wp_scripts'] = $existing_scripts;
			$GLOBALS['wp_styles']  = $existing_styles;
		}

		// Apply the cached diff now, or defer until after wp_enqueue_scripts
		// fires so cached handles print in the same relative cascade position
		// they would during a non-cached render.
		The_Cached_Content\apply_or_defer_replay( $data );

		/**
		 * Perform additional actions when outputting cached content.
		 *
		 * @param array $data Data in cache.
		 */
		do_action( 'cached_content_output', $data );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $data['the_content'];
	}
}
