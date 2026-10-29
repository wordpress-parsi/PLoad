=== Pload – Smart Image Optimizer & WebP ===
Contributors: wordpress-parsi
Tags: image optimization, webp, compress images, performance, lazy load
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts your images to WebP a little at a time, so even a cheap shared host can optimize a large media library without timeouts.

== Description ==

Most image optimizers try to convert the whole library as fast as possible. On shared hosting that ends in timeouts, memory errors or a host warning about CPU usage. Pload takes the slow road: it first tests what your server can handle, then converts a few hundred images every minute and stays inside those limits. A library of 200,000 images simply takes a couple of days instead of crashing.

Your original files are never changed. Pload writes a WebP copy next to each JPEG and PNG (photo.jpg gets photo.jpg.webp) and keeps it only if it is really smaller.

= What it does =

* Tests the server before starting: which image engine works (Imagick or GD), how much memory and time a step may use, and how much disk space the WebP files will need.
* Converts in short background steps through WP-Cron. If WP-Cron is off or blocked, site visits, an open admin page, a server cron job or a free online cron service can drive the steps instead.
* Handles new uploads first, then works through the library, newest images first.
* Shows WebP to browsers that support it with a <picture> tag. No .htaccess rules, so it works on Apache, Nginx and LiteSpeed, and with page caches.
* Works with theme lazy loading (WoodMart and similar themes that keep the real address in data-src).
* Optional compatibility mode for images that page builders print without the WordPress image functions.
* Pauses by itself when disk space runs low. On cPanel it reads the real account quota, not the size of the whole server disk.
* Three quality levels, or your own number, and an option to rebuild existing WebP files after a change.
* Exclusions by image, by page address or by CSS class.
* A Pload column in the media library: optimize, apply new settings or exclude an image with one click, and see the saving for every size.
* Control the maximum size of new uploads (WordPress keeps the original).
* Optional CDN address for the WebP files.
* One click removes every WebP file again, a little at a time, before you delete the plugin.

= Privacy =

Everything happens on your own server. Pload does not send images or any other data anywhere and needs no account or API key.

== Installation ==

1. Install and activate the plugin.
2. Go to Media → Pload. The server test and the disk space estimate run by themselves.
3. Pick a quality and click "Start optimizing".
4. Once the first WebP files exist, turn on "Show WebP to visitors" and clear your page cache.

== Frequently Asked Questions ==

= Will it change my original images? =

No. WebP files are saved next to the originals. If you turn off "Show WebP to visitors", the site is back to the original images right away.

= Why is it slow? =

On purpose. Each step stays within the time and memory the server test measured, so the site keeps running normally. On a VPS you can set the processing speed to "Fast".

= Does it support AVIF? =

Not yet. The server test already checks whether your host can create AVIF files; converting to AVIF is planned for a later version.

= Does it work with a CDN? =

Yes. If your CDN mirrors the site (a pull zone), put its address in Settings → Advanced → CDN address and the WebP files are loaded from it. If a CDN plugin already rewrites all image addresses, leave the field empty.

= How do I remove everything? =

Go to Media → Pload → Tools and use "Remove all WebP files", then delete the plugin. Deleting the plugin removes its settings too, but it only has a few seconds to delete files, so on a large library run the removal first.

== Screenshots ==

1. Overview while optimizing: progress, space saved, time to finish and the switch that shows WebP to visitors.
2. First visit: the server test and disk space estimate run by themselves; pick a quality and start.
3. Settings: quality level, exclusions and advanced options.
4. Tools: library re-check, disk space with the cPanel quota, activity log and a report for support.

== Changelog ==

= 1.1.0 =
* New Pload column in the media library: Optimize, Apply new settings and Don't optimize buttons that work without reloading the page, the saving for each image and a details list for every size. The same block appears in the attachment details.
* New setting: maximum size of new uploads. WordPress scales larger images and keeps the original.
* New setting: CDN address for the WebP sources.
* New quality levels based on a benchmark with real store photos: Balanced is now 75 (was 80) and High quality 85 (was 90), for clearly smaller files with no visible difference. Existing WebP files are kept; the Pload page offers to rebuild them.

= 1.0.0 =
* First public release.
