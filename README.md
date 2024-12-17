# the_cached_content()

This repository defines a template function to fragment-cache the entire `the_content()` output in a transient for a given post object.

Any block assets (scripts and styles) which are registered or enqueued during content rendering are tracked and stored in the same transient alongside the rendered content and re-injected into the global scripts registry when rendered content is used, so that conditional asset dependency includes like block scripts continue to work even when blocks are not `->render()`d during page generation.

## Installation

This project can be installed using Composer as `humanmade/the-cached-content`.
