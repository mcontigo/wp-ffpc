<?php
/*
Plugin Name: WP-FFPC
Version: 0.2.2
Plugin URI: http://petermolnar.eu/wordpress/wp-ffpc
Description: Fast Full Page Cache, backend can be memcached or APC
Author: Peter Molnar
Author URI: http://petermolnar.eu/
License: GPL2
*/

/*  Copyright 2010-2011 Peter Molnar  (email : hello@petermolnar.eu )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

/* fix */
if ( ! defined( 'WP_PLUGIN_URL' ) )
	define( 'WP_PLUGIN_URL', get_option( 'siteurl' ) . '/wp-content/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
	define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );

/* constants */
define ( 'WP_FFPC_PARAM' , 'wp-ffpc' );
define ( 'WP_FFPC_OPTION_GROUP' , 'wp-ffpcparams' );
define ( 'WP_FFPC_OPTIONS_PAGE' , 'wp-ffpcoptions' );
define ( 'WP_FFPC_URL' , WP_PLUGIN_URL . '/' . WP_FFPC_PARAM  );
define ( 'WP_FFPC_DIR' , WP_PLUGIN_DIR . '/' . WP_FFPC_PARAM );
define ( 'WP_FFPC_CONF_DIR' , WP_PLUGIN_DIR . '/' . WP_FFPC_PARAM .'/config' );
define ( 'WP_FFPC_ACACHE_MAIN_FILE' , ABSPATH . 'wp-content/advanced-cache.php' );
define ( 'WP_FFPC_ACACHE_INC_FILE' , WP_FFPC_DIR. '/advanced-cache.php' );
define ( 'WP_FFPC_ACACHE_COMMON_FILE' , WP_FFPC_DIR. '/wp-ffpc-common.php' );
define ( 'WP_FFPC_CONFIG_VAR' , '$wp_ffpc_config' );

include_once (WP_FFPC_DIR .'/wp-ffpc-common.php');

if (!class_exists('WPFFPC')) {

	/**
	 * main class
	 *
	 */
	class WPFFPC {

		/* for options array */
		var $options = array();
		/* for default options array */
		var $defaults = array();
		/* memcached server object */
		var $memcached = NULL;
		var $memcached_string = '';

		/* status, 0 = nothing happened*/
		var $status = 0;

		/**
		* constructor
		*
		*/
		function __construct() {

			/* register options */
			$this->get_options();

			/* check is backend is available */
			$alive = wp_ffpc_init( $this->options );

			/* don't register hooks if backend is dead */
			if ($alive)
			{
				/* init inactivation hooks */
				add_action('switch_theme', array( $this , 'invalidate'), 0);
				add_action('edit_post', array( $this , 'invalidate'), 0);
				add_action('publish_post', array( $this , 'invalidate'), 0);
				add_action('delete_post', array( $this , 'invalidate'), 0);

				/* Capture and register if a redirect is sent back from WP, so the cache
				can cache (or ignore) it. Redirects were source of problems for blogs
				 with more than one host name (eg. domain.com and www.domain.com) comined
				with the use of Hyper Cache.*/
				add_filter('redirect_canonical', array( $this , 'redirect_canonical') , 10, 2);
			}

			/* add admin styling */
			if( is_admin() )
			{
				wp_enqueue_style( WP_FFPC_PARAM . '.admin.css' , WP_FFPC_URL . '/css/'. WP_FFPC_PARAM .'.admin.css', false, '0.1');
			}

			/* on activation */
			register_activation_hook(__FILE__ , array( $this , 'activate') );

			/* on deactivation */
			register_deactivation_hook(__FILE__ , array( $this , 'deactivate') );

			/* on uninstall */
			register_uninstall_hook(__FILE__ , array( $this , 'uninstall') );

			/* init plugin in the admin section */
			/* if multisite, admin page will be on network admin section */
			if ( MULTISITE )
				add_action('network_admin_menu', array( $this , 'admin_init') );
			/* not network, will be in simple admin menu */
			else
				add_action('admin_menu', array( $this , 'admin_init') );
		}

		/**
		 * activation hook: save default settings in order to eliminate bugs.
		 *
		 */
		function activate ( ) {
			$this->save_settings( true );
		}

		/**
		 * init function for admin section
		 *
		 */
		function admin_init () {
			/* register options */
			add_site_option( WP_FFPC_PARAM, $this->options , '' , 'no');

			/* save parameter updates, if there are any */
			if ( isset($_POST[WP_FFPC_PARAM . '-save']) )
			{
				$this->save_settings ();
				$this->status = 1;
				header("Location: admin.php?page=" . WP_FFPC_OPTIONS_PAGE . "&saved=true");
			}

			add_menu_page('Edit WP-FFPC options', __('WP-FFPC', WP_FFPC_PARAM ), 10, WP_FFPC_OPTIONS_PAGE , array ( $this , 'admin_panel' ) );
		}

		/**
		 * settings panel at admin section
		 *
		 */
		function admin_panel ( ) {

			/**
			 * security
			 */
			if( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ){
				die( );
			}

			/**
			 * if options were saved
			 */
			if ($_GET['saved']=='true' || $this->status == 1) : ?>
				<div id='setting-error-settings_updated' class='updated settings-error'><p><strong>Settings saved.</strong></p></div>
			<?php endif;


			/**
			 * the admin panel itself
			 */
			?>
			<?php if ( !WP_CACHE ) : ?>
				<div class="updated settings-error"><p><strong>WARNING: WP_CACHE is disabled, plugin will not work that way. Please add define( 'WP_CACHE', true ); into the beginning of wp-config.php</strong></p></div>
			<?php endif; ?>

			<div class="wrap">
			<h2><?php _e( 'NMC settings', WP_FFPC_PARAM ) ; ?></h2>
			<form method="post" action="#">

				<fieldset>
				<legend><?php _e( 'Global settings', WP_FFPC_PARAM ); ?></legend>
				<dl>
					<div class="grid50">

					<dt>
						<label for="cache_type"><?php _e('Cache invalidation method', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<select name="cache_type" id="cache_type">
							<?php $this->cache_type ( $this->options['cache_type'] ) ?>
						</select>
						<span class="description"><?php _e('Select cache invalidation method.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->cache_type( $this->defaults['cache_type'] , true ) ; ?></span>
					</dd>

					<dt>
						<label for="expire"><?php _e('Entry invalidation time', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="number" name="expire" id="expire" value="<?php echo $this->options['expire']; ?>" />
						<span class="description"><?php _e('How long will an entry be valid', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['expire']; ?></span>
					</dd>

					<dt>
						<label for="charset"><?php _e('Charset to send data with.', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="text" name="charset" id="charset" value="<?php echo $this->options['charset']; ?>" />
						<span class="description"><?php _e('Charset of HTML and XML (pages and feeds) data.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['charset']; ?></span>
					</dd>

					<dt>
						<label for="invalidation_method"><?php _e('Cache invalidation method', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<select name="invalidation_method" id="invalidation_method">
							<?php $this->invalidation_method ( $this->options['invalidation_method'] ) ?>
						</select>
						<span class="description"><?php _e('Select cache invalidation method. <p><strong>WARNING! When selection "all", the cache will be fully flushed, including elements that were set by other applications.</strong></p>', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->invalidation_method( $this->defaults['invalidation_method'] , true ) ; ?></span>
					</dd>

					<dt>
						<label for="prefix_data"><?php _e('Data prefix', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="text" name="prefix_data" id="prefix_data" value="<?php echo $this->options['prefix_data']; ?>" />
						<span class="description"><?php _e('Prefix for HTML content keys, can be used in nginx.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['prefix_data']; ?></span>
					</dd>

					<dt>
						<label for="prefix_meta"><?php _e('Meta prefix', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="text" name="prefix_meta" id="prefix_meta" value="<?php echo $this->options['prefix_meta']; ?>" />
						<span class="description"><?php _e('Prefix for meta content keys, used only with PHP processing.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['prefix_meta']; ?></span>
					</dd>

					<dt>
						<label for="debug"><?php _e("Enable debug mode", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="debug" id="debug" value="1" <?php checked($this->options['debug'],true); ?> />
						<span class="description"><?php _e('An additional header, "X-Cache-Engine" will be added when pages are served through WP-FFPC.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['debug']); ?></span>
					</dd>

					<dt>
						<label for="pingback_status"><?php _e("Enable pingback links", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="pingback_status" id="pingback_status" value="1" <?php checked($this->options['pingback_status'],true); ?> />
						<span class="description"><?php _e('Enable "X-Pingback" headers in cached pages; will always use accessed hostname as host!', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['pingback_status']); ?></span>
					</dd>

					</div>

					<div class="grid50">
					<dt>
						<label for="cache_loggedin"><?php _e('Enable cache for logged in users', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="cache_loggedin" id="cache_loggedin" value="1" <?php checked($this->options['cache_loggedin'],true); ?> />
						<span class="description"><?php _e('Cache pages even if user is logged in.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['cache_loggedin']); ?></span>
					</dd>

					<dt>
						<label for="nocache_home"><?php _e("Don't cache home", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="nocache_home" id="nocache_home" value="1" <?php checked($this->options['nocache_home'],true); ?> />
						<span class="description"><?php _e('Exclude home page from caching', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['nocache_home']); ?></span>
					</dd>

					<dt>
						<label for="nocache_feed"><?php _e("Don't cache feeds", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="nocache_feed" id="nocache_feed" value="1" <?php checked($this->options['nocache_feed'],true); ?> />
						<span class="description"><?php _e('Exclude feeds from caching.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['nocache_feed']); ?></span>
					</dd>

					<dt>
						<label for="nocache_archive"><?php _e("Don't cache archives", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="nocache_archive" id="nocache_archive" value="1" <?php checked($this->options['nocache_archive'],true); ?> />
						<span class="description"><?php _e('Exclude archives from caching.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['nocache_archive']); ?></span>
					</dd>

					<dt>
						<label for="nocache_single"><?php _e("Don't cache posts (and single-type entries)", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="nocache_single" id="nocache_single" value="1" <?php checked($this->options['nocache_single'],true); ?> />
						<span class="description"><?php _e('Exclude singles from caching.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['nocache_single']); ?></span>
					</dd>

					<dt>
						<label for="nocache_page"><?php _e("Don't cache pages", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="nocache_page" id="nocache_page" value="1" <?php checked($this->options['nocache_page'],true); ?> />
						<span class="description"><?php _e('Exclude pages from caching.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['nocache_page']); ?></span>
					</dd>
					</div>
				</dl>
				</fieldset>

				<fieldset class="grid50">
				<legend><?php _e('Settings for memcached backend', WP_FFPC_PARAM); ?></legend>

				<?php if ( !class_exists('Memcache') && !class_exists('Memcached')  ): ?>
					<h1 class="error">No PHP memcached extension was found. To use memcached, you need PHP Memcache or PHP Memcached extension.</h1>
				<?php endif; ?>

				<div>
					<strong><?php _e( 'Memcached server status: ', WP_FFPC_PARAM ) ; ?>
					<?php
						$server_status = wp_ffpc_init( $this->options);

						$server_status = ( empty($server_status) || $server_status == 0 ) ? '<span class="error-msg">down</span>' : '<span class="ok-msg">up & running</span>' ;
						echo $server_status;
					?>
					</strong>
				</div>

				<dl>

					<dt>
						<label for="host"><?php _e('Host', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="text" name="host" id="host" value="<?php echo $this->options['host']; ?>" />
						<span class="description"><?php _e('Hostname for memcached server', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['host']; ?></span>
					</dd>

					<dt>
						<label for="port"><?php _e('Host', WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="number" name="port" id="port" value="<?php echo $this->options['port']; ?>" />
						<span class="description"><?php _e('Port for memcached server', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php echo $this->defaults['port']; ?></span>
					</dd>

				</dl>
				</fieldset>

				<?php if ( $this->options['cache_type'] == 'memcache' ) : ?>

				<fieldset class="grid50">
				<legend><?php _e('Sample config for nginx to utilize the data entries', WP_FFPC_PARAM); ?></legend>
				<pre>location / {
	try_files $uri $uri/ @memcached;
}

location @memcached {
	default_type text/html;
	set $memcached_key <?php echo $this->options['prefix_data'];?>$host$request_uri;
	memcached_pass <?php echo $this->options['host'];?>:<?php echo $this->options['port'];?>;
	error_page 404 = @rewrites;
}

location @rewrites {
	rewrite ^(.*)$ /index.php?q=$1 last;
}</pre>
				</fieldset>

				<? endif; ?>

				<fieldset class="grid50">
				<legend><?php _e('Settings for APC', WP_FFPC_PARAM); ?></legend>
				<dl>

					<dt>
						<label for="apc_compress"><?php _e("Compress entries", WP_FFPC_PARAM); ?></label>
					</dt>
					<dd>
						<input type="checkbox" name="apc_compress" id="apc_compress" value="1" <?php checked($this->options['apc_compress'],true); ?> />
						<span class="description"><?php _e('Try to compress APC entries. Requires PHP ZLIB.', WP_FFPC_PARAM); ?></span>
						<span class="default"><?php _e('Default ', WP_FFPC_PARAM); ?>: <?php $this->print_bool( $this->defaults['apc_compress']); ?></span>
					</dd>

				</dl>
				</fieldset>

				<p class="clearcolumns"><input class="button-primary" type="submit" name="<?php echo WP_FFPC_PARAM; ?>-save" id="<?php echo WP_FFPC_PARAM; ?>-save" value="Save Changes" /></p>
			</form>
			<?php

		}

		/**
		 * generates cache type select box
		 *
		 * @param $current
		 * 	the active or required size's identifier
		 *
		 * @param $returntext
		 * 	boolean: is true, the description will be returned of $current size
		 *
		 * @return
		 * 	prints either description of $current
		 * 	or option list for a <select> input field with $current set as active
		 *
		 */
		function cache_type ( $current , $returntext = false ) {

			$e = array (
				'apc' => 'use APC as store',
				'memcache' => 'use memcached server with Memcache extension',
				'memcached' => 'use memcached server with Memcached extension',
			);

			$this->print_select_options ( $e , $current , $returntext );

		}

		/**
		 * deactivation hook: clear advanced-cache config file
		 *
		 */
		function deactivate ( ) {
			if (@file_exists (WP_FFPC_ACACHE_MAIN_FILE))
				@unlink (WP_FFPC_ACACHE_MAIN_FILE);
		}

		/**
		 * invalidate cache
		 */
		function invalidate ( $post_id ) {
			wp_ffpc_clear ( $post_id );
		}

		/**
		 * generates invalidation method select box
		 *
		 * @param $current
		 * 	the active or required size's identifier
		 *
		 * @param $returntext
		 * 	boolean: is true, the description will be returned of $current size
		 *
		 * @return
		 * 	prints either description of $current
		 * 	or option list for a <select> input field with $current set as active
		 *
		 */
		function invalidation_method ( $current , $returntext = false ) {

			$e = array (
				0 => 'all cached pages (WARNING! Flushes _all_ cached entrys! )',
				1 => 'only modified post',
			);

			$this->print_select_options ( $e , $current , $returntext );
		}

		/**
		 * generates main advanced-cache system-wide config file
		 *
		 */
		function generate_config() {

			$acache = WP_FFPC_ACACHE_MAIN_FILE;
			/* is file currently exists, delete it*/
			if ( @file_exists( $acache ))
				unlink ($acache);

			/* is deletion was unsuccessful, die, we have no rights to do that */
			if ( @file_exists( $acache ))
				return false;

			$string = '<?php'. "\n" .
'global '. WP_FFPC_CONFIG_VAR .' ;' . "\n";

			foreach($this->options as $key => $val) {
				if (is_string($val))
					$val = "'" . $val . "'";

				$string .= WP_FFPC_CONFIG_VAR . '[\'' . $key . '\']=' . $val . ";\n";
			}

			$string .= "\n\ninclude_once ('" . WP_FFPC_ACACHE_COMMON_FILE . "');\ninclude_once ('" . WP_FFPC_ACACHE_INC_FILE . "');\n";

			file_put_contents($acache, $string);
			return true;
		}

		/**
		 * parameters array with default values;
		 *
		 */
		function get_options ( ) {
			$defaults = array (
				'port'=>11211,
				'host'=>'127.0.0.1',
				'expire'=>300,
				'invalidation_method'=>0,
				'prefix_meta' =>'meta-',
				'prefix_data' =>'data-',
				'charset' => 'utf-8',
				'pingback_status'=> false,
				'debug' => 1,
				'cache_type' => 'memcache',
				'cache_loggedin' => false,
				'nocache_home' => false,
				'nocache_feed' => false,
				'nocache_archive' => false,
				'nocache_single' => false,
				'nocache_page' => false,
				'apc_compress' => false,
			);

			$this->defaults = $defaults;

			$this->options = get_site_option( WP_FFPC_PARAM , $defaults, false );
		}

		/**
		 * prints `true` or `false` depending on a bool variable.
		 *
		 * @param $val
		 * 	The boolen variable to print status of.
		 *
		 */
		function print_bool ( $val ) {
			$bool = $val? 'true' : 'false';
			echo $bool;
		}

		/**
		 * select field processor
		 *
		 * @param sizes
		 * 	array to build <option> values of
		 *
		 * @param $current
		 * 	the current resize type
		 *
		 * @param $returntext
		 * 	boolean: is true, the description will be returned of $current type
		 *
		 * @return
		 * 	prints either description of $current
		 * 	or option list for a <select> input field with $current set as active
		 *
		 */
		function print_select_options ( $sizes, $current, $returntext=false ) {

			if ( $returntext )
			{
				_e( $sizes[ $current ] , WP_FFPC_PARAM);
				return;
			}

			foreach ($sizes as $ext=>$name)
			{
				?>
				<option value="<?php echo $ext ?>" <?php selected( $ext , $current ); ?>>
					<?php _e( $name , WP_FFPC_PARAM); ?>
				</option>
				<?php
			}

		}

		/**
		 * function to be able to store redirects
		 */
		function redirect_canonical($redirect_url, $requested_url) {
			global $wp_nmc_redirect;
			$wp_nmc_redirect = $redirect_url;
			return $redirect_url;
		}

		/**
		 * save settings function
		 *
		 */
		function save_settings ( $firstrun = false ) {

			/**
			 * update params from $_POST
			 */
			foreach ($this->options as $name=>$optionvalue)
			{
				if (!empty($_POST[$name]))
				{
					$update = $_POST[$name];
					if (strlen($update)!=0 && !is_numeric($update))
						$update = stripslashes($update);
				}
				elseif ( ( empty($_POST[$name]) && is_bool ($this->defaults[$name]) ) || is_numeric( $update ) )
				{
					$update = 0;
				}
				else
				{
					$update = $this->defaults[$name];
				}
				$this->options[$name] = $update;
			}

			update_site_option( WP_FFPC_PARAM , $this->options );

			$this->invalidate('system_flush');

			if ( ! $firstrun )
				$this->generate_config();

		}

		/**
		 * clean up at uninstall
		 *
		 */
		function uninstall ( ) {
			delete_site_option( WP_FFPC_PARAM );
		}

	}
}

/**
 * instantiate the class
 */
$wp_nmc = new WPFFPC();


?>