=== Infinite Scroll Gallery for Elementor ===
Requires at least: 5.8
Requires PHP: 7.4
Requires Plugins: elementor
Tested up to: 6.8
Stable tag: 1.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Endless parallax gallery with GSAP Flip image-to-content transitions, video slides, WooCommerce add-to-cart and category filtering.

== Description ==

An Elementor widget that turns a list of images (or your WooCommerce products) into the endless, parallax
image column from the Codrops "Infinite Scroll Gallery and Flip Transitions in GSAP" demo. Clicking a picture
morphs it into a full content view with the title, description, price, rating, add-to-cart button and a
thumbnail strip.

== Changelog ==

= 1.7.0 =
* Editorial split-screen content view: full-height photo column, title / description / thumbnail strip aligned
  to the end edge, exactly like the reference design.
* New self-hosted display face (Host Grotesk, SIL OFL) so the layout needs no external font request.
* New "Content view thumbnails" strip under the description: for WooCommerce products it shows the product
  gallery, for manual slides it shows the neighbouring slides. Clicking a frame swaps the big preview.
* New Elementor control "Show thumbnails in the content view" (on by default).
* The strip is excluded from the text split animation, so its frames stay on one row.
* Plugin files moved to the standard layout (assets/css, assets/js, assets/js/vendor, assets/fonts, includes).
* Removed a stray code block that had been pasted into the stylesheet.

= 1.6.0 =
* Video slides (MP4 / YouTube / Vimeo), WooCommerce add-to-cart, category filter, accessibility and
  no-JS fallback work.
