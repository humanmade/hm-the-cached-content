# Changelog

## v0.2.0

- Adds two new hooks that can be used to handle custom data like global state when rendering cached content: `cached_content_data` filter to add additional data to the cached object, and `cached_content_output` to handle this data when rendering the output.

## v0.1.0

- Initial release: implement a `the_cached_content()` method for fragment-caching page content (default 5 minutes), and automatically restore all block-enqueued styles, scripts, and inline CSS when cached content is served.
