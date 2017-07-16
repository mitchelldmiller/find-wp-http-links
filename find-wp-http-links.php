<?php
/**
 Plugin Name:  Find WordPress HTTP Links
 Description:  Find http links on https site.
 Version:      1.0.1
 Author:       Mitchell D. Miller
 Author URI:   https://wheredidmybraingo.com/about/
 Plugin URI:   https://wheredidmybraingo.com/find-wordpress-http-links/
 Text Domain:  find-wp-http-links
 Domain Path:  /lang
 License:      GPLv3
 License URI:  http://www.gnu.org/licenses/gpl.html
 */

/**
 * Find http links on an https WordPress site.
 * 
 * Finds mixed content in posts, pages, postmeta, options. Displays report with links to edit items. Replaces content on selected posts.
 * 
 * Includes 5 Javascript functions <script>
 * done fwhl_replace_custom / fwhl_replace_custom_javascript = replace custom post content.
 * done fwhl_replace_links / fwhl_replace_text_javascript = replace links on a single post.
 * done fwhl_replace_content_js / fwhl_replace_published_content_script = replace all published post / page content.
 * done fwhl_replace_other_meta_js / fwhl_replace_unpublished_meta_javascript = replace unpublished post meta.
 * fwhl_replace_meta_js / fwhl_replace_published_meta_javascript = replace published postmeta.
 */
Class Find_WP_Http_Links {
	
	/**
	 * Default number of links displayed on a page.
	 * @var integer
	 */
	const DEFAULT_LINKS_PER_PAGE = 20;
	
	/**
	 * Are we running on a test site? This is a replacement search target.
	 * @var string address of real site
	 */
	public $fake_http;
	
	/**
	 * Test for https. Add menu, plugin support link.
	 * @since 1.0.0
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array($this, 'is_using_https') );
		add_action( 'admin_menu', array($this, 'init_fwhl_menu') );
		add_action( 'wp_ajax_fwhl_replace_text', array($this, 'fwhl_replace_text'));
		add_action( 'wp_ajax_fwhl_replace_published_meta', array($this, 'fwhl_replace_published_meta'));
		add_action( 'wp_ajax_fwhl_replace_unpublished_meta', array($this, 'fwhl_replace_unpublished_meta'));
		add_action( 'wp_ajax_fwhl_replace_custom', array($this, 'fwhl_replace_custom'));
		add_action( 'wp_ajax_fwhl_replace_published_content', array($this, 'fwhl_replace_published_content'));
		add_action( 'admin_footer', array($this, 'fwhl_replace_text_javascript') );
		add_action( 'admin_footer', array($this, 'fwhl_replace_published_meta_javascript') );
		add_action( 'admin_footer', array($this, 'fwhl_replace_unpublished_meta_javascript') );
		add_action( 'admin_footer', array($this, 'fwhl_replace_custom_javascript') );	
		add_action( 'admin_footer', array($this, 'fwhl_replace_published_content_script') );
		add_action( 'plugins_loaded', array($this, 'init_fwhl_translation') );
		add_filter( 'plugin_row_meta', array($this, 'fwhl_plugin_links'), 10, 2 );
	} // end constructor
	
	/**
	 * get the database value for home.
	 * 
	 * @return string|NULL
	 */
	public static function get_db_home() {
		global $wpdb;
		$query = "SELECT `option_value` FROM `{$wpdb->prefix}options` WHERE `option_name` = %s";
		$sql = $wpdb->prepare($query, 'home');
		return untrailingslashit( $wpdb->get_var( $sql ) );
	}
	
	/**
	 * if site does not use https, do not install plugin, display message, exit.
	 * @since 1.0.0
	 */
	public function is_using_https()
	{
		$db_home = self::get_db_home();
		$url = get_bloginfo( 'url' ); // get_option( 'home' );
		$url = untrailingslashit($url);
		$is_https = stristr( $url, 'https://' );
		if ( !$is_https && !empty($db_home) && $db_home != $url ) { 
			$is_https = stristr( $db_home, 'https://' ); // fake_https
		} // end if testing db home
		
		if ( !$is_https ) {
			deactivate_plugins( basename( __FILE__ ) );
			$url = get_bloginfo( 'url' );
			$message = sprintf('%s. %s', __( 'Requires https', 'find-wp-http-links' ), __( 'This site', 'find-wp-http-links' ));
			echo "<div class='notice notice-error is-dismissible'>{$message}: {$url}</div>";
			exit;
		}
	} // end is_using_https
	
	/**
	 * add http links to tools menu.
	 * @since 1.0.0
	 */
	public function init_fwhl_menu() {
		$title = __( 'Find Http Links', 'find-wp-http-links' );
		$page = add_submenu_page( 'tools.php', $title, $title,
		apply_filters( 'whl_user_capability', 'edit_published_posts' ),
		'wp-http-links-results', array($this, 'get_content') );
	} // end init_fwhl_menu
	
	/**
	 * Check options for http links.
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 * @since 1.0.0
	 */
	public function check_options( $http ) {
		global $wpdb;
		$retval = array('total' => 0, 'title' => '', 'invalid' => 0);
		$like_http = '%' . $wpdb->esc_like( $http ) . '%';
		$not_like_widget = '%' . $wpdb->esc_like( 'widget' ) . '%';
		$template = "select COUNT(`option_value`) as `q` from $wpdb->options where 
		`option_name` not like %s and option_value like %s";
		$sql = $wpdb->prepare($template, $not_like_widget, $like_http);
		$retval['invalid'] = intval( $wpdb->get_var( $sql ) );
		if ($retval['invalid'] > 0) {
			$template = "select `option_name` from $wpdb->options
			where `option_name` not like %s and option_value like %s limit 0, 1";
			$sql = $wpdb->prepare($template, $not_like_widget, $like_http);		
			$retval['title'] = $wpdb->get_var( $sql );
		}
	
		$sql = "select COUNT(`option_value`) as `q` from $wpdb->options";
		$retval['total'] = intval( $wpdb->get_var( $sql ) );
		return $retval;
	} // end check_options
	
	/**
	 * check video widgets for http links.
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 * @since 1.0.0
	 */
	public function check_video_widgets( $http ) {
		global $wpdb;
		$retval = array('total' => 0, 'title' => '', 'invalid' => 0);
		// get total first
		$sql = "select `option_value` from $wpdb->options where `option_name` = 'widget_media_video'";
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		$retval['total'] = is_array( $widgets ) ? count( $widgets ) : 0;
		if ( 0 == $retval['total'] ) {
			return $retval;
		} // end if no image widgets
	
		$like = '%' . $wpdb->esc_like( $http ) . '%';
		$template = "select `option_value` from $wpdb->options where `option_name` = %s and option_value like '%{$http}%'";
		$sql = $wpdb->prepare($template, 'widget_media_video', $like);		
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		if (empty( $data ) || empty( $widgets )) {
			return $retval;
		}
		
		$keys = array_keys( $widgets );
		$title = '';
		foreach ( $keys as $q ) {
			if ( !is_array( $widgets[$q] ) ) {
				continue;
			}
			foreach( $widgets[$q] as $k => $v ) {
				if ('title' == $k) {
					$title = $v;
				} // end if
				if ( stristr( $v, $http ) ) {
					$retval['invalid']++;
					if ( empty( $retval['title'] ) ) {
						$retval['title'] = $title;
					} // end if need title
				} // end if match
			} // end foreach
		} // end foreach
		return $retval;
	} // end check_video_widgets
	
	/**
	 * check image widgets for http links.
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 * @since 1.0.0
	 */
	public function check_image_widgets( $http ) {
		global $wpdb;
		$retval = array('total' => 0, 'title' => '', 'invalid' => 0);
		// get total first
		$sql = "select `option_value` from $wpdb->options where `option_name` = 'widget_media_image'";
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		$retval['total'] = is_array( $widgets ) ? count( $widgets ) : 0;
		if (0 == $retval['total']) {
			return $retval;
		} // end if no image widgets
	
		$like = '%' . $wpdb->esc_like( $http ) . '%';
		$old_sql = "select `option_value` from $wpdb->options where `option_name` = 'widget_media_image' 
		and option_value like '%{$http}%'";
		$template = "select `option_value` from $wpdb->options where `option_name` = %s and option_value like %s";
		$sql = $wpdb->prepare($template, 'widget_media_image', $like);		
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		if (empty( $data ) || empty( $widgets )) {
			return $retval;
		}
		$keys = array_keys( $widgets );
		foreach ($keys as $q) {
			if ( empty( $widgets[$q]['url'] ) && empty( $widgets[$q]['link_url'] ) )  {
				continue;
			}
			$image_url = empty( $widgets[$q]['url'] ) ? 'n/a' : $widgets[$q]['url'];
			$link_url = empty( $widgets[$q]['link_url'] ) ? 'n/a' : $widgets[$q]['link_url'];
			$title = empty( $widgets[$q]['title'] ) ? 'n/a' : $widgets[$q]['title'];
			if ( stristr( $image_url, $http ) || stristr( $link_url, $http )) {
				$retval['invalid']++;
				if ( empty($retval['title'] ) ) {
					$retval['title'] = $title;
				}
			} // end if found http link
		} // end foreach
		return $retval;
	} // end check_image_widgets
	
	/**
	 * check rss widgets for http links
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 */
	public function check_rss_widgets( $http ) {
		$retval = array('total' => 0, 'title' => '', 'invalid' => 0);
		$qtitle = '';
		$original = get_option('widget_rss' );
		if (!is_array($original) || empty($original)) {
			return $retval;
		} // end if empty
		foreach ($original as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $kk => $vv) {
					if ($kk == 'title') {
						$retval['total']++;
						$qtitle = $vv;
					}
					if (is_string($vv) && strstr($vv, $http)) {
						$retval['invalid']++;
						if (!empty($qtitle) && empty($retval['title'])) {
							$retval['title'] = $qtitle;
						}
					} // end if
				} // end foreach
			} elseif (is_string($v) && strstr($v, $http)) {
				$retval['invalid']++;
			} // end if array
		} // end outer foreach
		return $retval;
	} // end check_rss_widgets
	
	/**
	 * check rss, image, video widgets
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 * @since 1.0.0
	 */
	public function check_special_widgets( $http ) {
		// $total = array('total' => 0, 'title' => '', 'invalid' => 0);
		$images = $this->check_image_widgets( $http );
		$videos = $this->check_video_widgets( $http );
		$rss = $this->check_rss_widgets( $http );
		$title = '';
		if (!empty($videos['title'])) {
			$title = $videos['title'];
		} elseif (!empty($images['title'])) {
			$title = $images['title'];
		} elseif (!empty($rss['title'])) {
			$title = $rss['title'];
		}
		$total = $images['total'] + $videos['total'] + $rss['total'];
		$invalid = $images['invalid'] + $videos['invalid'] + $rss['invalid'];
		return array('total' => $total, 'title' => $title, 'invalid' => $invalid);
	} // end check_special_widgets
	
	/**
	 * check widgets for http links.
	 * @param string $http http version of blog url
	 * @return array widget info: total, total invalid, first bad title
	 * @since 1.0.0
	 */
	public function check_all_widgets( $http ) {
		global $wpdb;
		$retval = $this->check_special_widgets( $http ); 
		// array('total' => 0, 'title' => '', 'invalid' => 0);
		// get total here
		$like_widget = '%' . $wpdb->esc_like( 'widget' ) . '%';
		$template = "select `option_value` from $wpdb->options where `option_name` like %s";
		$sql = $wpdb->prepare($template, 'widget_text');
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		$found = is_array( $widgets ) ? count( $widgets ) : 0;
		$retval['total'] = $found; // cumulative count here
		if (0 == $retval['total']) {
			return $retval;
		} // end if no widgets
	
		$old_sql = "select option_value from {$wpdb->prefix}options where option_name = 
		'widget_text' and option_value like '%{$http}%'";
		
		$like = '%' . $wpdb->esc_like( $http ) . '%';
		$template = "select `option_value` from {$wpdb->prefix}options where `option_name` = %s and option_value like %s";
		$sql = $wpdb->prepare($template, 'widget_text', $like);
		$data = $wpdb->get_var( $sql );
		$widgets = unserialize( $data );
		if (empty( $data ) || empty( $widgets ) ) {
			return $retval;
		}
		foreach ($widgets as $q) {
			if (empty($q['text'])) {
				continue;
			}
			if (stristr($q['text'], $http)) {
				$retval['invalid']++;
				if (empty($retval['title'])) {
					$retval['title'] = $q['title'];
				}
			}
		} // end for each
		return $retval;
	} // end check_all_widgets
	
	/**
	 * check posts for http links.
	 * @param string $http http:// link
	 * @param array $meta meta results
	 * @since 1.0.0
	 */
	public function check_posts($http, $meta) {
		global $wpdb;
		$msql = '';
		if ($meta['total'] > 0) {
			$msql = 'OR ID IN(';
			for ($i = 0; $i < $meta['total']; $i++) {
				$msql .= "{$meta['all_meta'][$i]['post_id']},";
			} // end for
			$msql = substr($msql, 0, -1) . ')';
		} // end if
		$retval = array();
		$sql = "select ID, post_title from $wpdb->posts where 
		post_status = 'publish' and post_content like '%{$http}%' and (post_type = 'post' 
		or post_type = 'page') {$msql} order by post_title";
		$posts = $wpdb->get_results($sql, ARRAY_A);
		$j = count($posts);
		foreach ($posts as $post) {
			$retval[$post['ID']] = $post['post_title'];
		}
		return $retval;
	} // end check_posts
	
	/**
	 * check custom posts for http links.
	 * @param string $http http:// link
	 * @return array matching post IDs
	 * @since 1.0.0
	 */
	public function check_custom($http) {
		global $wpdb;
		$retval = array();
		$sql = "select ID from {$wpdb->prefix}posts where post_status = 'publish' and post_content like '%{$http}%' and post_type != 'post' and post_type != 'page'";
		$posts = $wpdb->get_results($sql, ARRAY_A);
		$j = count($posts);
		foreach ($posts as $post) {
			$retval[] = $post['ID'];
		}
		return $retval;
	} // end check_custom
	
	/*
	 * display custom post results
	 * @param array $docs IDs of custom posts with http links
	 */
	public function show_custom($docs) {
		$j = count($docs);
		$color = ($j > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$num = '';
		if ($j == 0) {
			$num = __( 'No http links in custom posts', 'find-wp-http-links' ) . '.';
		} else {
			$num = sprintf( _n('%s published custom post with http link', 
					'%s published custom posts with http link', 
					$j, 'find-wp-http-links' ), $j ) . '.';
			$num .= sprintf(' <a class="wp-ui-text-primary" onclick="fwhl_replace_custom()" href="javascript:;">%s</a></span>', __( 'Fix Custom Posts', 'find-wp-http-links' ));
		}
		echo "<p class='{$color}'>{$num}</p>";
	} // end show_custom
	
	/**
	 * replace text on custom post content. ignores posts and pages.
	 */
	public function fwhl_replace_custom() {
		global $wpdb;
		$from = esc_url_raw($_POST['from']);
		$to = esc_url_raw($_POST['to']);		
		check_ajax_referer( 'fwhl_replace_custom', 'security' );
		$custom = $this->check_custom( $from );
		$msg = '';
		foreach ( $custom as $id ) {
			$sql = "update {$wpdb->prefix}posts set post_content = replace(post_content, '{$from}', '{$to}') where ID = {$id}";
			$result = $wpdb->query( $sql );
			if ( 1 != $result ) {
				$msg = empty( $wpdb->last_error ) ? __( 'Error updating custom posts', 'find-wp-http-links' ) : htmlspecialchars( $wpdb->last_error );
				break;
			} // end if error		
		} // end foreach
		if (empty($msg)) {
			ob_start();
			$custom = $this->check_custom($from);
			$this->show_custom($custom);
			echo ob_get_clean();
		} else {
			echo $msg;
		}
		
		wp_die();
	} // end fwhl_replace_custom
	
	/**
	 * script for replace http in custom posts
	 */
	function fwhl_replace_custom_javascript() {
		$ajax_nonce = wp_create_nonce( 'fwhl_replace_custom' );
?>
			<script type="text/javascript">
			function fwhl_replace_custom() {
				var working = ' <span class="wp-ui-text-primary" style="padding-left:2em">' + jQuery('#fwh_working').text() + ' </span>';
				jQuery('#fwhl_custom').html(working);
				
				var from = jQuery('#fwh_http').text();
				var to = jQuery('#fwh_url').text();
				var security = '<?php echo $ajax_nonce; ?>';
				var jqxhr = jQuery.post( ajaxurl, 
				{ action: 'fwhl_replace_custom', from: from, to: to, security: security } );
				jqxhr.always(function( response ) {
				  	if (response == '0') {
						response = 'NOT FOUND';
					}
				  	jQuery('#fwhl_custom').html(response);
					return true;
				  });
			} // end function
			</script>
	<?php
		} // end fwhl_replace_custom_javascript
	
	/**
	 * display widget results.
	 * @param array $widgets
	 * @since 1.0.0
	 */
	public function show_widgets( $widgets ) {
		$color = ($widgets['invalid'] > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$css = "class='{$color}'";
		$num = '';
		$wresults = '';
		if ($widgets['total'] == 0) {
			echo "<p {$css}>" . __( 'No widgets with text found.', 'find-wp-http-links' ) . '</p>';
			return;
		}
	
		$num = sprintf( _n('Checked %s widget', 'Checked %s widgets', $widgets['total'], 'find-wp-http-links' ), $widgets['total'] ) . '.';
		$num = "<span class='wp-ui-text-primary'>{$num}</span>";
		if ($widgets['invalid'] > 0) {
			$num .= ' ' . sprintf( _n('%s widget with http link', '%s widgets with http links', $widgets['invalid'], 'find-wp-http-links' ), $widgets['invalid'] );
			if ($widgets['invalid'] > 1) {
				$num .= '. ' . __( 'Including', 'find-wp-http-links' ) . ' ';
			} else {
				$num .= '. <span class="wp-ui-text-primary">' . __( 'See', 'find-wp-http-links' ) . '</span> ';
			} // end if
	
			$num .= '<a target="_blank" href="/wp-admin/widgets.php">' . htmlspecialchars( $widgets['title'], ENT_QUOTES ) . '</a>';
		} else {
			$num .= ' ' . __( 'No http links on widgets', 'find-wp-http-links' ) . '.	';
		} // end if invalid widgets
	
		echo "<p {$css}>{$num}</p>";
	} // end show_widgets

	/**
	 * get content
	 * @param integer $paged page number
	 */
	public function get_content($paged) {
		
		$site_url = get_bloginfo( 'url' ); // get_option( 'home' );
		$site_url = untrailingslashit($site_url);
		$is_https = stristr( $site_url, 'https://' );
		if ( !$is_https ) {
			$site_url = self::get_db_home();			
		}
		
		$http = str_ireplace('https://', 'http://', $site_url);
		$options = $this->check_options( $http );
		$widgets = $this->check_all_widgets( $http );
		$content = $this->check_published_content( $http );
		$meta = $this->check_published_meta( $http );
		$other = $this->check_unpublished_meta($http);
		$custom = $this->check_custom( $http );
		$posts = $this->check_posts( $http, $meta );
		$paged = empty( $_GET['paged'] ) ? 1 : intval( $_GET['paged'] );
		
		$parts = explode( '&', $_SERVER['REQUEST_URI'] );
		$url = $parts[0];
		$links = count( $posts );
		/**
		 * filter total links per page
		 * @since 1.0.0
		 * @param integer `self::DEFAULT_LINKS_PER_PAGE`
		 */
		$links_per_page = apply_filters( 'whl_links_per_page', self::DEFAULT_LINKS_PER_PAGE );
		if ( 1 > $links_per_page ) {
			$links_per_page = self::DEFAULT_LINKS_PER_PAGE;
		} // end if invalid setting
		
		$pages = intval( ceil( $links / $links_per_page ) );
		if ($paged < 1 || $paged > $pages) {
			$paged = 1;
		}
		$digits = strlen( strval( $pages ) );
		$start = ($paged - 1) * $links_per_page;
		$np = '#';
		$fp = ($paged > 1) ? "{$url}&amp;paged=1" : '#';
		$lp = ($paged == $pages) ? '#' : "{$url}&amp;paged={$pages}";
		if ($pages > $paged) {
			$num = $paged + 1;
			$np = "{$url}&amp;paged={$num}";
		}
		$prev_url = '#';
		if ($paged > 1) {
			$num = $paged - 1;
			$prev_url = "{$url}&amp;paged={$num}";
		} // end if
		$here = empty( $_GET['page'] ) ? '' : $_GET['page'];
?>
	<div class="wrap">
<?php	
	echo sprintf( '<h2>%s</h2>', __( 'Find HTTP Links on HTTPS Site', 'find-wp-http-links' ) );
	$preload = __( 'Reloading. Please wait.', 'find-wp-http-links' );
	$pworking = __( 'Working', 'find-wp-http-links' );
	if (!$is_https) {
		$pleft = __( 'FAKE MODE', 'find-wp-http-links' );
		$pmid = __( 'Searching for', 'find-wp-http-links' );
		echo sprintf('<h3><span class="wp-ui-text-highlight" style="padding-right:2em;">%s</span> %s %s</h3>',
		$pleft, $pmid, $http);
	}
	echo "<p style='display:none;'><span id='fwh_working'>{$pworking}</span><span id='fwh_loading'>{$preload}</span><span id='fwh_url'>{$site_url}</span><span id='fwh_http'>{$http}</span></p>";
?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<div class="postbox">
						<div class="inside">
							<table id="fwhl_table" class="widefat">
	<?php echo $this->getLinksTable( $paged, $meta, $posts, $links_per_page ); ?>
							</table>
	
	<?php if ( $pages > 1 ) : ?>						
	<div class="tablenav">
		<div class="tablenav-pages">
		<form method="get" action="<?php echo $url; ?>">
		<a class="first-page" href="<?php echo $fp; ?>">&laquo;</a>
		<a class="prev-page" href="<?php echo $prev_url; ?>">&lsaquo;</a>
		<span class="paging-input">
		<input type="hidden" name="page" value="<?php echo $here; ?>">
		<input class="current-page" type="text" size="<?php echo $digits; ?>"
		name="paged" value="<?php echo $paged; ?>"></span> / 
		<span class="total-pages"><?php echo $pages; ?></span>
			<a class="next-page" href="<?php echo $np; ?>">&rsaquo;</a>
			<a class="last-page" href="<?php echo $lp; ?>">&raquo;</a>
			</form>
		</div>
	</div>
	<?php endif; ?>				
						</div> <!-- .inside -->
					</div> <!-- .postbox -->
				</div> <!-- post-body-content -->
	
				<div id="postbox-container-1" class="postbox-container">
					<div class="meta-box-sortables">
						<div class="postbox">
							<h2 style="border-bottom: 1px solid black; text-align: center;">	<?php _e( 'Analysis', 'find-wp-http-links' ); ?></h2>
							<div class="inside">
							<?php 
								$this->show_options( $options );
								$this->show_widgets( $widgets );
								echo '<div id="fwhl_content">';
								$this->show_published_content( $content );
								echo '</div>';
								echo '<div id="fwhl_meta">';
								$this->show_published_meta( $meta );
								echo '</div>';
								echo '<div id="fwhl_other">';
								$this->show_unpublished_meta( $other );
								echo '</div>';								
								echo '<div id="fwhl_custom">';
								$this->show_custom($custom);
								echo '</div>';
							?>
							</div>
						</div>
						<div class="postbox">
							<h2 style="border-bottom: 1px solid black; text-align: center;"><?php _e('About', 'find-wp-http-links' ); ?></h2>
							<div class="inside">
							<p>See <a target="_blank" style="text-decoration: underline;"  href="https://wheredidmybraingo.com/find-wordpress-http-links/">How to Find http WordPress Links on https Sites</a></p>
								<p>Copyright &copy; 2017 <a href="https://wheredidmybraingo.com/tampa-wordpress-developer/">Mitchell D. Miller</a></p>
							</div>
						</div> <!-- .postbox -->
					</div> <!-- .meta-box-sortables -->
				</div> <!-- #postbox-container-1 .postbox-container -->
			</div>
			<br class="clear">
		</div>
	</div>
<?php
	} // end get_content
	
	/**
	 * check content in published posts and pages for http links
	 * @param string $http http link
	 * @return array $content all post IDs
	 */
	public function check_published_content( $http ) {
		global $wpdb;
		$sql = "select ID from {$wpdb->prefix}posts where post_content like '%{$http}%' and post_status = 'publish' and (post_type = 'post' or post_type = 'page')";
		$metadata = $wpdb->get_results( $sql, ARRAY_A );
		$m = count( $metadata );
		$content = array();
		for ($i = 0; $i < $m; $i++) {
			$content[] = $metadata[$i]['ID'];
		} // end for

		return $content;
	} // end check_published_content
	
	/*
	 * display meta results
	 * @param array $posts matching post IDs
	 */
	public function show_published_content( $content ) {
		$j = count($content);
		$color = ($j > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$num = '';
		if ($j == 0) {
			$num = __( 'No http links in published posts or pages', 'find-wp-http-links' ) . '.';
		} else {
			$num = sprintf( _n('%s published documents with http link', '%s published documents with http links', 'find-wp-http-links' ), $j ) . '.';
			$num .= sprintf(' <a class="wp-ui-text-primary" onclick="fwhl_replace_content_js()" href="javascript:;">%s</a></span>', __( 'Replace Published Content', 'find-wp-http-links' ));
		}
		echo "<p class='{$color}'>{$num}</p>";
	} // end show_published_content
	
	/**
	 * replace all published http links in postmeta
	 * @return string message with results
	 */
	function fwhl_replace_published_content() {
		global $wpdb;
		$from = esc_url_raw($_POST['from']);
		$to = esc_url_raw($_POST['to']);		
		check_ajax_referer( 'fwhl_replace_published', 'security' );		
		$all_content = $this->check_published_content($from);
		$j = count($all_content);
		$not_fixed = 0;
		$wpdb->show_errors(true);
		$table = "{$wpdb->prefix}posts";
		$format = array('%s');
		$where_format = array('%d');
		for ($i = 0; ($i < $j) && ($not_fixed == 0); $i++) {
			$sql = "select post_content from {$wpdb->prefix}posts where ID = {$all_content[$i]}";
			$content = $wpdb->get_var( $sql );
			$https = str_ireplace($from, $to, $content);
			$data = array('post_content' => $https);
			$where = array('ID' => $all_content[$i]);			
			$result = $wpdb->update($table, $data, $where, $format, $where_format);
			if (empty($result) && $wpdb->last_error != '') {
				$msg = empty($wpdb->last_error) ? "db error: ID {$i}" : $wpdb->last_error;
				error_log("637: {$msg}");
				$not_fixed++;
			} // end if
		} // end for
		
		if (0 != $not_fixed) {
			$msg = sprintf( _n('Error replacing text on %s published document',
					'Error replacing text on %s published document',
					$not_fixed, 'find-wp-http-links' ), $not_fixed ) . '.';
			echo "<p class='wp-ui-text-highlight'>{$msg}</p>";
		} else {
			echo "OK";
		} // end if error

		wp_die();
	} // end fwhl_replace_published_content
	
	function fwhl_replace_published_content_script() {
		$ajax_nonce = wp_create_nonce( 'fwhl_replace_published' );
?>
				<script type="text/javascript">
				function fwhl_replace_content_js(id) {
					var working = ' <span class="wp-ui-text-primary" style="padding-left:2em">' + jQuery('#fwh_working').text() + ' </span>';
					jQuery('#fwhl_content').html(working);
					var from = jQuery('#fwh_http').text();
					var to = jQuery('#fwh_url').text();
					var security = '<?php echo $ajax_nonce; ?>';
					var jqxhr = jQuery.post( ajaxurl, 
						{ action: 'fwhl_replace_published_content', from: from, to: to, security: security });
					jqxhr.always(function( response ) {
						  	if (response == '0') {
								response = 'fwhl_replace_published_content NOT FOUND';
							} else if (response == 'OK') {
								response = '';
							} // end if error
							if (response != '') {
						  		jQuery('#fwhl_content').html(response);
							} else {
								var lmsg = '<span class="wp-ui-text-highlight" style="font-size:120%;">' + jQuery('#fwh_loading').html() + '</span>';
								jQuery('#fwhl_caption').html(lmsg);										
							  	location.reload(true);
							} // end if display error or reload
	  					  	return true;
					}); // end always
				} // end function
				</script>
<?php
			} // end fwhl_replace_published_meta_content	
			
	/**
	 * check postmeta in published posts for http links
	 * @param string $http http link
	 * @return array total, all_meta
	 */
	public function check_published_meta( $http ) {
		global $wpdb;
		$sql = "select post_id, meta_key from {$wpdb->prefix}postmeta where meta_value like '%{$http}%' and post_id in (select ID from {$wpdb->prefix}posts where post_status = 'publish' and (post_type = 'post' or post_type = 'page'))";
		$metadata = $wpdb->get_results( $sql, ARRAY_A );
		$m = count( $metadata );
		$all_meta = array();
		for ($i = 0; $i < $m; $i++) {
			$matched = false;
			for ($j = $i + 1; !$matched && $j < $m; $j++)
			{
				$matched = ($metadata[$i]['post_id'] == $metadata[$j]['post_id']);
			}
			if (!$matched) {
				$all_meta[] = array('post_id' => $metadata[$i]['post_id'], 'meta_key' => $metadata[$i]['meta_key']);
			}
		} // end for
		$m = count($all_meta);
		return array('total' => $m, 'all_meta' => $all_meta);
	} // end check_published_meta
	
	/**
	 * check postmeta that is NOT in published posts for http links
	 * @param string $http http link
	 * @return array matching meta IDs
	 */
		
	public function check_unpublished_meta( $http ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $http ) . '%';
		$template = "select post_id, meta_key from $wpdb->postmeta
		where meta_value like %s and post_id not in (select ID from $wpdb->posts where
		post_status = '%s' and (post_type = '%s' or post_type = '%s'))";
		$sql = $wpdb->prepare($template, $like, 'publish', 'post', 'page');	
		$metadata = $wpdb->get_results( $sql, ARRAY_A );
		$m = count( $metadata );
		$all_meta = array();
		for ($i = 0; $i < $m; $i++) {
			$all_meta[] = array('post_id' => $metadata[$i]['post_id'], 'meta_key' => $metadata[$i]['meta_key']);
		} // end for
		return array('total' => $m, 'all_meta' => $all_meta);
	} // end check_unpublished_meta
	
	/**
	 * show option results
	 * @param array $options options report
	 */
	public function show_options($options) {
		$onum = number_format( floatval( $options['total'] ), 0 );
		$ochecked = sprintf( "%s %s %s.", __( 'Checked', 'find-wp-http-links' ), $onum, __( 'options', 'find-wp-http-links' ) );
		$ofound = '';
		$color = ($options['invalid'] > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$css = "<span class='{$color}'>";
		if ($options['invalid'] == 0) {
			echo "<p class='{$color}'>" . __( 'No options with http links', 'find-wp-http-links' ) . '.	</p>';
			return;
		} // end if no invalid options
		$otitle = sprintf ( " <span class='wp-ui-text-primary'>%s &lsquo;%s&rsquo;.</span>", __ ( 'See', 'find-wp-http-links' ), $options['title'] );
		$olink = sprintf ( ' <a href="/wp-admin/options.php" target="_blank">%s</a>', __ ( 'Check options', 'find-wp-http-links' ) );
		if ( 1000 > $options['invalid'] ) {
			$ofound = sprintf ( _n( '%s option with http link', '%s options with http links', $options['invalid'], 'find-wp-http-links' ), $options['invalid'] ) . '. ' . $otitle . $olink;
		} else {			
			$onum = number_format( floatval( $options['invalid'] ), 0 );
			$ofound = sprintf('%s %s', $onum, __( 'options with http links', 'find-wp-http-links' ) );
		} // end if need number format
		echo "\r\n<p>{$ochecked} {$css}{$ofound}</span></p>\r\n";
	} // end show_options
	
	/*
	 * display meta results
	 * @param array $meta
	 */
	public function show_published_meta($meta) {
		$color = ($meta['total'] > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$num = '';
		if ($meta['total'] == 0) {
			$num = __( 'No http links in published postmeta', 'find-wp-http-links' ) . '.';
		} else {
			$num = sprintf( _n('%s published document with http postmeta link', '%s published documents with http postmeta links', 'find-wp-http-links' ), $meta['total'] ) . '.';
			$num .= sprintf(' <a class="wp-ui-text-primary" onclick="fwhl_replace_meta_js()" href="javascript:;">%s</a></span>', __( 'Fix Published Meta', 'find-wp-http-links' ));
		}
		echo "<p class='{$color}'>{$num}</p>";
	} // end show_published_meta

	/*
	 * display meta results
	 * @param array $meta
	 */
	public function show_unpublished_meta($other) {
		$color = ($other['total'] > 0) ? 'wp-ui-text-highlight' : 'wp-ui-text-primary';
		$num = '';
		if ($other['total'] == 0) {
			$num = __( 'No http links in other postmeta', 'find-wp-http-links' ) . '.';
		} else {
			$num = sprintf( _n('%s other postmeta value with http link', 
					'%s other postmeta values with http links', $other['total'], 'find-wp-http-links' ), $other['total'] ) . '.';
			$num .= sprintf(' <a class="wp-ui-text-primary" onclick="fwhl_replace_other_meta_js()" href="javascript:;">%s</a></span>', __( 'Fix Unpublished Meta', 'find-wp-http-links' ));
		}
		echo "<p class='{$color}'>{$num}</p>";
	} // end show_unpublished_meta
	
	/**
	 * replace text on a single post's content. gets ID, from from _POST.
	 */
	public function fwhl_replace_text() {
		global $wpdb;
		$id = intval($_POST['id']);
		$from = esc_url_raw($_POST['from']);
		$to = esc_url_raw($_POST['to']);
		check_ajax_referer( 'fwhl_replace_text', 'security' );
		$sql = "update {$wpdb->prefix}posts set post_content = replace(post_content, '{$from}', '{$to}') where ID = {$id}";
		$result = $wpdb->query( $sql );
		$msg = '';
		if ( 1 != $result ) {
			$msg = empty( $wpdb->last_error ) ? __( 'Error updating document', 'find-wp-http-links' ) : htmlspecialchars( $wpdb->last_error );
		} // end if error
		echo $msg;
		wp_die();
	} // end fwhl_replace_text

	function fwhl_replace_text_javascript() {
		$ajax_nonce = wp_create_nonce( 'fwhl_replace_text' );
?>
		<script type="text/javascript">
		function fwhl_replace_links(id) {
			var working = ' <span class="wp-ui-text-primary" style="padding-left:2em">' + jQuery('#fwh_working').text() + ' </span>';
			var sid = '#s' + id;
			jQuery(sid).html(working);
			var from = jQuery('#fwh_http').text();
			var to = jQuery('#fwh_url').text();
			var security = '<?php echo $ajax_nonce; ?>';
			var jqxhr = jQuery.post( ajaxurl, { 
				action: 'fwhl_replace_text', id: id, from: from, to: to, security: security });
			jqxhr.always(function( response ) {
				  if (response == '0') {
						response = 'NOT FOUND';
					}
					if (response == '') {
						jQuery('#r' + id).hide();
					} else {
						jQuery(sid).html(response);
					}

					var qrows = jQuery('#fwhl_table tr:visible').length;
					if (qrows < 1) {
						var lmsg = '<span class="wp-ui-text-highlight" style="font-size:120%;">' + jQuery('#fwh_loading').html() + '</span>';
						jQuery('#fwhl_caption').html(lmsg);
						location.reload(true);
					} // end if deleted last table row
					return true;
			  });
		} // end function
		</script>
<?php
	} // end fwhl_replace_text_javascript
	
	/**
	 * replace all published http links in postmeta
	 * @return string message with results
	 */
	function fwhl_replace_published_meta() {
		global $wpdb;
		$from = esc_url_raw($_POST['from']);
		$to = esc_url_raw($_POST['to']);
		check_ajax_referer( 'fwhl_replace_published_meta', 'security' );
		$sql = "select post_id, meta_key from {$wpdb->prefix}postmeta where meta_value like '%{$from}%' and post_id in (select ID from {$wpdb->prefix}posts where post_status = 'publish' and (post_type = 'post' or post_type = 'page'))";
		$all = $wpdb->get_results($sql, ARRAY_A);
		$not_fixed = 0;
		foreach ($all as $one) {			
			$prev_value = get_post_meta( $one['post_id'], $one['meta_key'], true );
			$data = @unserialize($prev_value);
			if (empty($data)) {
				$https = str_ireplace($from, $to, $prev_value);
				$result = update_post_meta($one['post_id'], $one['meta_key'], $https);
				if ($result == false) {
					$not_fixed++;
				} // end if
				continue;
			} // end if not serialized

			$cannot_check = false;
			foreach ($data as $few) {
				if ($few instanceof __PHP_Incomplete_Class || is_object($few)) {
					$cannot_check = true;
				} // end if
			} // end foreach
			if ($cannot_check) {
				error_log("Cannot check meta {$one['meta_key']}");
				$not_fixed++;
				continue;
			} // end if
	
			$meta_value = array();
			foreach ($data as $k => $v) {
				if (strstr($v, $from)) {
					$https = str_ireplace($from, $to, $v);
					$meta_value[$k] = $https;
				} else {
					$meta_value[$k] = $v;
				} // end if match
			} // end foreach
			$updated_value = serialize($meta_value);
			$result = update_post_meta($one['post_id'], $one['meta_key'], $updated_value, $prev_value);
			if ($result == false) {
				$not_fixed++;
			} // end if
		} // end foreach

		echo $not_fixed; // ignored. page is reloaded on return.
		wp_die();
	} // end fwhl_replace_published_meta
	
	function fwhl_replace_published_meta_javascript() {
		$ajax_nonce = wp_create_nonce( 'fwhl_replace_published_meta' );
?>
			<script type="text/javascript">
			function fwhl_replace_meta_js(id) {
				var working = ' <span class="wp-ui-text-primary" style="padding-left:2em">' + jQuery('#fwh_working').text() + ' </span>';
				jQuery('#fwhl_meta').html(working);
				var from = jQuery('#fwh_http').text();
				var to = jQuery('#fwh_url').text();
				var security = '<?php echo $ajax_nonce; ?>';
				var jqxhr = jQuery.post( ajaxurl, 
					{ action: 'fwhl_replace_published_meta', from: from, to: to, security: security } );
				jqxhr.always(function( response ) {
					  	if (response == '0') {
							response = 'fwhl_replace_published_meta NOT FOUND';
						} 
					  	// jQuery('#fwhl_meta').html(response);
						var lmsg = '<span class="wp-ui-text-highlight" style="font-size:120%;">' + jQuery('#fwh_loading').html() + '</span>';
						jQuery('#fwhl_caption').html(lmsg);										
					  	location.reload(true);
  					  	return true;
				}); // end always
			} // end function
			</script>
<?php
		} // end fwhl_replace_published_meta_javascript

		/**
		 * replace http links in unpublished postmeta
		 * @return string message with results
		 */
		function fwhl_replace_unpublished_meta() {
			global $wpdb;		
			$from = esc_url_raw($_POST['from']);
			$to = esc_url_raw($_POST['to']);
			check_ajax_referer( 'fwhl_replace_unpublished_meta', 'security' );			
			$all = $this->check_unpublished_meta($from);
			$not_fixed = 0;
			foreach ($all['all_meta'] as $one) {
				$prev_value = get_post_meta( $one['post_id'], $one['meta_key'], true );
				if ($one instanceof __PHP_Incomplete_Class) {
					error_log("Cannot update meta {$one['meta_key']}: __PHP_Incomplete_Class");
					$not_fixed++;
					continue;
				}
				if ( !is_array($prev_value) ) {
					if (strstr($prev_value, $from)) {
						$fixed = str_replace($from, $to, $prev_value);
						$result = update_post_meta($one['post_id'], $one['meta_key'], $fixed, $prev_value);
						if ($result == false) {
							$not_fixed++;
							error_log("Cannot update meta {$one['meta_key']} to {$updated_value}");
						} // end if cannot update
						continue;
					} // end if match
				} // end if not array
			
				$updated_value = array();
				foreach ($prev_value as $k => $v) {
					if (is_array($v)) {
						$zq = print_r($v, true);
						error_log("not fixed: key {$one['meta_key']} = {$zq}");
						$not_fixed++;
						break;
					}
					if (!is_string($v)) {
						$updated_value[$k] = $v;
						continue;
					} // end if not string
			
					$fixed = str_ireplace($from, $to, $v);
					$updated_value[$k] = $fixed;
				} // end foreach
				
				$result = update_post_meta($one['post_id'], $one['meta_key'], $updated_value, $prev_value);
				if ($result === false) {
					$not_fixed++;
					$zq = print_r($updated_value, true);
					error_log("Cannot update post {$one['post_id']} / {$one['meta_key']} to:\r\n{$zq}");
				} // end if not fixed
			} // end foreach	
			
			$meta = array('total' => $not_fixed);
			ob_start();
			$this->show_unpublished_meta($meta);
			echo ob_get_clean();
			wp_die(); // $prev_value
		} // end fwhl_replace_unpublished_meta
		
		function fwhl_replace_unpublished_meta_javascript() {
			$ajax_nonce = wp_create_nonce( 'fwhl_replace_unpublished_meta' );
?>
<script type="text/javascript">
function fwhl_replace_other_meta_js(id) {
	var working = ' <span class="wp-ui-text-primary" style="padding-left:2em">' + jQuery('#fwh_working').text() + ' </span>';
	jQuery('#fwhl_other').html(working);
	var from = jQuery('#fwh_http').text();
	var security = '<?php echo $ajax_nonce; ?>';
	var to = jQuery('#fwh_url').text();				
	var jqxhr = jQuery.post( ajaxurl,
		{ action: 'fwhl_replace_unpublished_meta', from: from, to: to, security: security } ); 
		jqxhr.always(function( response ) {
			if (response == '0') {
				response = 'fwhl_replace_unpublished_meta NOT FOUND';
			}
				jQuery('#fwhl_other').html(response);
  				return true;
		}); // end always
} // end function
</script>
<?php
				} // end fwhl_replace_unpublished_meta_javascript
		
	/**
	 * get message with http / https info.
	 * @param integer $paged
	 * @param array $meta
	 * @param array $posts
	 * @param integer $links_per_page
	 * @return string content of links table
	 */
	public function getLinksTable($paged, $meta, $posts, $links_per_page) {
		$j = count($posts);
		if (0 == $j) {
			return '<tr><th style="font-weight: bold;">' . __( 'Congratulations! No http links found in content of published posts and pages.', 'find-wp-http-links' ) . '</th></tr>' ;
		} // end if not found
		$start = ($paged - 1) * $links_per_page;
		$bg = array('', 'class="alternate"');
		$color = $bg[0];
		$template = "<tr id='r%d'><td %s><a target='_blank' href='/wp-admin/post.php?post=%d&amp;action=edit'>%s</a>%s</td></tr>";
		$keys = array_keys( $posts );
		$ktotal = count( $keys );
		$num = ($ktotal > $links_per_page - 1) ? $links_per_page : $ktotal;
		$l = ($ktotal > $start + $links_per_page) ? $start + $links_per_page : $ktotal;
		$pages = intval( ceil( $ktotal / $links_per_page ) );
		$displaying = __( 'Page', 'find-wp-http-links' ) . " {$paged} / {$pages} ";
		$meta_word = __( 'META', 'find-wp-http-links' );
		$presults = "<caption id='fwhl_caption' style='caption-side: top;'>{$displaying}</caption>";
		for ( $k = $start; $k < $l; $k++ ) {
			$key = $keys[$k];
			$mlabel = '';
			for ($i = 0; $i < $meta['total'] && empty($mlabel); $i++) {
				if ($meta['all_meta'][$i]['post_id'] == $key) {
					$mlabel = " ({$meta_word} {$meta['all_meta'][$i]['meta_key']})";
				} // end if
			} // end for
			if (empty($mlabel)) {
				$mlabel = sprintf('<span id="s%d">
				<a class="wp-ui-text-highlight" style="padding-left:2em;" 
				onclick="fwhl_replace_links(%d)" href="javascript:;">%s</a></span>', $key, $key, __( 'Fix Content', 'find-wp-http-links' ));
			} // end if
			$presults .= sprintf( $template, $key, $color, $key, $posts[$key], $mlabel );
			$color = ($color == $bg[0]) ? $bg[1] : $bg[0];
		} // end for

		return $presults;
	} // end getLinksTable
	
	/**
	 * add support link to plugin description. filters plugin_row_meta.
	 *
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function fwhl_plugin_links( $links, $file ) {
		$base = plugin_basename( __FILE__ );
		if ( $file == $base ) {
			$links[] = sprintf( '<a href="https://github.com/mitchelldmiller/find-wp-http-links/issues">%s</a>', __( 'Support', 'find-wp-http-links' ) );
		} // end if adding links
		return $links;
	} // end fwhl_plugin_links
	
	/**
	 * load translations
	 */
	public function init_fwhl_translation() {
		load_plugin_textdomain( 'find-wp-http-links', false, basename( dirname( __FILE__ ) ) . '/lang' );
	} // end init_fwhl_translation
	
	/**
	 * string representation
	 * @return string HTML summary
	 */
	public function __toString() {
		$site_url = get_bloginfo( 'url' ); // get_option( 'home' );
		$site_url = untrailingslashit( $site_url );
		$is_https = stristr( $site_url, 'https://' );
		if (!$is_https) {
			$site_url = self::get_db_home();
		}
		
		$http = str_ireplace( 'https://', 'http://', $site_url );		
		$options = $this->check_options( $http );
		$widgets = $this->check_all_widgets( $http );
		$content = $this->check_published_content( $http );
		$meta = $this->check_published_meta( $http );
		$other = $this->check_unpublished_meta($http);
		$posts = $this->check_posts( $http );
		$t_content = sprintf('%d %s', count($content), __( 'Documents with http links in content.', 'find-wp-http-links' ) );
		$t_other = sprintf('%d %s', count($other), __( 'Invalid other postmeta links.', 'find-wp-http-links' ) );
		$t_options = "{$options['invalid']} " . __( 'Invalid options.', 'find-wp-http-links' );
		$t_widgets = "{$widgets['invalid']} " . __( 'Invalid widgets.', 'find-wp-http-links' );
		$t_posts = strval( count( $posts ) ) . ' ' . __( 'Invalid posts.', 'find-wp-http-links' );
		$t_meta = "{$meta['total']} " . __( 'Invalid published postmeta links.', 'find-wp-http-links' );
		return $t_content . '<br>' . $t_other . '<br>' .
			$t_options . '<br>' . $t_widgets . '<br>' . $t_posts . '<br>' . $t_meta;
	} // end __toString
	
} // end class

new Find_WP_Http_Links();
?>