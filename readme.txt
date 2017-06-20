=== Find WP HTTP Links ===
Contributors: brainiac
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=4AAGBFXRAPFJY
Tags: ssl, mixed content, development, https
Requires at least: 4.4
Tested up to: 4.9
Stable tag: none
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl.html

Adds "Find Http Links" to Tools menu. Find / replace http links in posts, pages, postmeta, options, widgets.

== Description ==

Finds / replaces `http` links on an `https` WordPress site.

Includes _FAKE MODE_ to check copy of production database running on development server.

= Features =

* Finds `http` links on posts, pages, postmeta, options, widgets.

* Checks image and video widgets added in WP 4.8.

* Displays report with links to edit content.

* Can replace content, postmeta. Identifies, but does not replace options or widgets.

= Learn More =

* See [Find WordPress HTTP Links](https://wheredidmybraingo.com/find-wordpress-http-links/) for update info.

== Installation ==

1. Download the plugin and unpack in your `/wp-content/plugins` directory.

1. Activate the plugin through the 'Plugins' menu in WordPress.

1. Backup your database before using plugin.

== Frequently Asked Questions ==

* There are many varieties of widgets. This plugin checks text, image, video, RSS widgets.

* _FAKE MODE_ uses the `home` option in database, if it is different than `WP_HOME` in wp-config.php

== Screenshots ==

1. Analysis, results. 

== Changelog ==

= 1.0.0 =
* initial version.

== Upgrade Notice ==

= 1.0.0 =
* initial version.

== License ==

Find WP Http Links is free for personal or commercial use. Please encourage future development with a [donation](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=4AAGBFXRAPFJY "Donate with PayPal").

== Translators and Programmers ==

* A .pot file is included for translators.
