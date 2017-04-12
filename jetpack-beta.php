<?php

/**
 * Plugin Name: Jetpack Beta Tester
 * Plugin URI: https://github.com/Automattic/jetpack-beta
 * Description: Uses your auto-updater to update your local Jetpack to our latest beta version from the master-stable branch on GitHub. DO NOT USE IN PRODUCTION.
 * Version: 2.0
 * Author: Automattic
 * Author URI: https://jetpack.com/
 * License: GPLv2 or later
 *
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How this plugin works.
 * Jetpack beta manages files inside jetpack-dev folder this folder should contain
 *
 */
define( 'JPBETA__PLUGIN_FOLDER', plugins_url() . '/jetpack-beta/' );
define( 'JPBETA__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JPBETA__PLUGIN_FILE', __FILE__ );
define( 'JPBETA_VERSION', 2 );

define( 'JPBETA_DEFAULT_BRANCH', 'rc_only' );

define( 'JETPACK_BETA_MANIFEST_URL', 'https://betadownload.jetpack.me/jetpack-branches.json' );
define( 'JETPACK_ORG_API_URL', 'https://api.wordpress.org/plugins/info/1.0/jetpack.json' );
define( 'JETPACK_RC_API_URL', 'https://betadownload.jetpack.me/rc/rc.json' );
define( 'JETPACK_GITHUB_API_URL', 'https://api.github.com/repos/Automattic/Jetpack/' );
define( 'JETPACK_GITHUB_URL', 'https://github.com/Automattic/jetpack' );
define( 'JETPACK_DEFAULT_URL', 'https://jetpack.com' );

define( 'JETPACK_DEV_PLUGIN_SLUG', 'jetpack-dev' );

define( 'JETPACK_PLUGIN_FILE', 'jetpack/jetpack.php' );
define( 'JETPACK_DEV_PLUGIN_FILE', 'jetpack-dev/jetpack.php' );

define( 'JETPACK_BETA_REPORT_URL', 'https://github.com/Automattic/jetpack/issues/new' );


require_once 'autoupdate-self.php';
add_action( 'init', array( 'Jetpack_Beta_Autoupdate_Self', 'instance' ) );

class Jetpack_Beta {

	protected static $_instance = null;

	static $option = 'jetpack_beta_active';
	static $option_dev_installed = 'jetpack_beta_dev_currently_installed';

	/**
	 * Main Instance
	 */
	public static function instance() {
		return self::$_instance = is_null( self::$_instance ) ? new self() : self::$_instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( isset( $_GET['delete'] ) ) {
			delete_site_transient( 'update_plugins' );
		}

		add_filter( 'auto_update_plugin', array( $this, 'auto_update_jetpack_beta' ), 10, 2 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'api_check' ) );
		add_filter( 'upgrader_post_install', array( $this, 'upgrader_post_install' ), 10, 3 );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
		add_action( 'deactivate_plugin', array( $this, 'plugin_deactivated' ) , 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'deleted_plugin' ), 10, 2 );

		add_filter( 'plugin_action_links_' . JETPACK_PLUGIN_FILE, array( $this, 'remove_activate_stable' ) );
		add_filter( 'plugin_action_links_' . JETPACK_DEV_PLUGIN_FILE, array( $this, 'remove_activate_dev' ) );

		add_filter( 'network_admin_plugin_action_links_' . JETPACK_PLUGIN_FILE, array( $this, 'remove_activate_stable' ) );
		add_filter( 'network_admin_plugin_action_links_' . JETPACK_DEV_PLUGIN_FILE, array( $this, 'remove_activate_dev' ) );

		add_filter( 'all_plugins', array( $this, 'update_all_plugins' ) );

		if ( is_admin() ) {
			require JPBETA__PLUGIN_DIR . 'jetpack-beta-admin.php';
			Jetpack_Beta_Admin::init();
		}
	}

	public static function is_network_enabled() {
			if ( Jetpack_Beta::is_network_active() ) {
				add_filter( 'option_active_plugins', array( 'Jetpack_Beta','override_active_plugins' ) );
			}
	}

	/**
	 * @param $active_plugins
	 * Make sure that you can't have Jetpack and Jetpack Dev plugins versions loaded
	 * This filter is only applied if Jetpack is network activated.
	 * @return array
	 */
	public static function override_active_plugins( $active_plugins ) {
		$new_active_plugins = array();
		foreach( $active_plugins as $active_plugin ) {
			if ( ! self::is_jetpack_plugin( $active_plugin ) ) {
			$new_active_plugins[] = $active_plugin;
			}
		}
		return $new_active_plugins;
	}

	public function plugin_deactivated( $plugin, $network_wide ) {
		if ( ! Jetpack_Beta::is_jetpack_plugin( $plugin ) ) {
			return;
		}

		delete_option( Jetpack_Beta::$option );
	}

	public static function is_jetpack_plugin( $plugin ) {
		return in_array( $plugin, array( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE ) );
	}

	public function remove_activate_dev( $actions ) {
		if ( is_plugin_active( JETPACK_PLUGIN_FILE ) || self::is_network_active() ) {
			$actions['activate'] = __( 'Plugin Already Active', 'jetpack-beta' );
		}
		return $actions;
	}

	public function remove_activate_stable( $actions ) {
		if ( is_plugin_active( JETPACK_DEV_PLUGIN_FILE ) || self::is_network_active() ) {
			$actions['activate'] = __( 'Plugin Already Active', 'jetpack-beta' );
		}
		return $actions;
	}

	public function update_all_plugins( $plugins ) {
		foreach ( $plugins as $plugin_file => $plugin ) {
			if ( JETPACK_DEV_PLUGIN_FILE === $plugin_file ) {
				$plugins[ $plugin_file ] = $this->update_jetpack_dev( $plugin );
			}
		}
		return $plugins;
	}
	public function update_jetpack_dev( $plugin ) {
		$plugin['Name'] = $plugin['Name'] . ' | ' . Jetpack_Beta::get_jetpack_plugin_pretty_version( true );
		return $plugin;
	}
	/**
	 * Ran on activation to flush update cache
	 */
	public static function activate() {
		delete_site_transient( 'update_plugins' );

	}

	public static function get_plugin_file() {
		return self::get_plugin_slug() . '/jetpack.php';
	}

	public static function get_plugin_slug() {
		$installed = self::get_branch_and_section();
		if ( empty( $installed ) || $installed[1] === 'stable' ) {
			return 'jetpack';
		}
		return JETPACK_DEV_PLUGIN_SLUG;
	}

	public static function deactivate() {
		add_action( 'shutdown', array( __CLASS__, 'switch_active' ) );
		delete_option( self::$option );

		if ( is_multisite() ) {
			return;
		}

		// Delete the jetpack dev plugin
		$creds = request_filesystem_credentials( site_url() . '/wp-admin/', '', false, false, array() );
		if ( ! WP_Filesystem( $creds ) ) {
			/* any problems and we exit */
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return;
		}
		
		$working_dir = WP_PLUGIN_DIR . '/' . JETPACK_DEV_PLUGIN_SLUG;
		// delete the folder JETPACK_BETA_PLUGIN_FOLDER
		if ( $wp_filesystem->is_dir( $working_dir ) ) {
			$wp_filesystem->delete( $working_dir, true );
		}
		// Since we are removing this dev plugin we should also clean up this data.
		delete_option( self::$option_dev_installed );
	}

	static function admin_url( $query = '?page=jetpack-beta' ) {
		return ( Jetpack_Beta::is_network_active() )
			? network_admin_url( 'admin.php' . $query )
			: admin_url( 'admin.php' . $query );
	}

	public function admin_bar_menu() {
		global $wp_admin_bar;

		if ( ! is_object( $wp_admin_bar ) )
			return;

		// Nothing got activated yet.
		if ( ! self::get_option() ) {
			return;
		}

		$args = array(
			'id'    => 'jetpack-beta_admin_bar',
			'title' => 'Jetpack Beta',
			'parent' => 'top-secondary',
			'href'  => self::admin_url()
		);
		$wp_admin_bar->add_node( $args );

		// add a child item to our parent item
		$args = array(
			'id'     => 'jetpack-beta_version',
			'title'  => self::get_jetpack_plugin_pretty_version(),
			'parent' => 'jetpack-beta_admin_bar'
		);

		$wp_admin_bar->add_node( $args );

		if ( self::get_plugin_slug() === JETPACK_DEV_PLUGIN_SLUG ) {
			// Highlight the menu if you are running the BETA Versions..
			echo "<style>#wpadminbar #wp-admin-bar-jetpack-beta_admin_bar { background: #72af3a; }</style>";
		}

		$args = array(
			'id'     => 'jetpack-beta_report',
			'title'  => 'Report Bug',
			'href'   => JETPACK_BETA_REPORT_URL,
			'parent' => 'jetpack-beta_admin_bar'
		);
		$wp_admin_bar->add_node( $args );

	}
	
	public function api_check( $transient ) {
		// Check if the transient contains the 'checked' information
		// If not, just return its value without hacking it
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// We are running the regular Jetpack version..
		// The Site will update to the latest Jetpack Beta Version when we first switch...
		if ( self::get_plugin_slug() !== JETPACK_DEV_PLUGIN_SLUG ) {
			return $transient;
		}

		// Lets always grab the latest jetab
		delete_site_transient( 'jetpack_beta_manifest' );
		
		// check the version and decide if it's new

		if ( self::should_update() ) {
			$response              = new stdClass;
			$response->plugin      = self::get_plugin_slug();
			$response->new_version = self::get_new_jetpack_version();
			$response->slug        = self::get_plugin_slug();
			$response->url         = self::get_url();
			$response->package     = self::get_install_url();
			// If response is false, don't alter the transient
			if ( false !== $response ) {
				$transient->response[ self::get_plugin_file() ] = $response;
			}
			// unset the that it doesn't need an update...
			unset( $transient->no_update[ JETPACK_DEV_PLUGIN_FILE ] );
		}

		return $transient;
	}

	function should_update( ) {
		return version_compare( self::get_new_jetpack_version(), self::get_jetpack_plugin_version(), '>' );
	}

	/**
	 * Moves the newly downloaded folder into jetpack-dev
	 * @param $worked
	 * @param $hook_extras
	 * @param $result
	 *
	 * @return WP_Error
	 */
	public function upgrader_post_install( $worked, $hook_extras, $result ) {
		global $wp_filesystem;
		if ( $hook_extras['plugin'] !== JETPACK_DEV_PLUGIN_FILE ) {
			return $worked;
		}

		if ( $wp_filesystem->move( $result['destination'], WP_PLUGIN_DIR . '/' , true ) ) {
			return $worked;
		} else {
			return new WP_Error();
		}
		return $worked;
	}

	static function get_jetpack_plugin_version() {
		$info = self::get_jetpack_plugin_info();
		return $info['Version'];
	}

	static function get_option() {
		return get_option( self::$option );
	}

	static function get_dev_installed() {
		return get_option( self::$option_dev_installed );
	}

	static function get_branch_and_section() {
		$option = (array) self::get_option();
		if ( false === $option[0] ) {
			// see if the jetpack is plugin enabled
			if ( is_plugin_active( JETPACK_PLUGIN_FILE ) ) {
				return array( 'stable', 'stable' );
			}
			return array( false, false );
		}
		// branch and section
		return $option;
	}

	static function get_branch_and_section_dev() {
		$option = (array) self::get_dev_installed();
		if ( false !== $option[0] && isset( $option[1] )) {
			return array( $option[0], $option[1] );
		}
		return self::get_branch_and_section();
	}

	static function get_jetpack_plugin_pretty_version( $is_dev_version = false ) {
		if( $is_dev_version ) {
			list( $branch, $section ) = self::get_branch_and_section_dev();
		} else {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( ! $section  ) {
			return '';
		}

		if ( 'master' === $section ) {
			return 'Bleeding Edge';
		}

		if ( 'stable' === $section ) {
			return 'Latest Stable';
		}

		if ( 'rc' === $section ) {
			return 'Release Candidate';
		}

		if ( 'pr' === $section ) {
			$branch = str_replace( '-', ' ', $branch );
			return 'Feature Branch: ' . str_replace( '_', ' / ', $branch );
		}

		return self::get_jetpack_plugin_version();
	}

	static function get_new_jetpack_version() {
		$manifest = self::get_beta_manifest();

		list( $branch, $section ) = self::get_branch_and_section();

		if ( 'master' === $section && isset( $manifest->{$section}->version ) ) {
			return $manifest->{$section}->version;
		}

		if ( isset( $manifest->{$section}->{$branch}->version ) ) {
			return $manifest->{$section}->{$branch}->version;
		}
		return 0;
	}

	static function get_url( $branch = null, $section = null ) {
		if ( is_null ( $section ) ) {
			list( $branch, $section ) = self::get_branch_and_section();
		}
		
		if ( 'master' === $section ) {
			return JETPACK_GITHUB_URL . '/tree/master-build';
		}

		if ( 'rc' === $section ) {
			return JETPACK_GITHUB_URL . '/tree/' . $section . '-build';
		}

		if ( 'pr' === $section ) {
			$manifest = self::get_beta_manifest();
			return isset( $manifest->{$section}->{$branch}->pr )
				? JETPACK_GITHUB_URL  . '/pull/' . $manifest->{$section}->{$branch}->pr
				: JETPACK_DEFAULT_URL;
		}
		return JETPACK_DEFAULT_URL;
	}

	static function get_install_url( $branch = null, $section = null ) {
		if ( is_null( $section ) ) {
			list( $branch, $section ) = self::get_branch_and_section();
		}

		if ( 'stable' === $section ) {
			$org_data = self::get_org_data();
			return $org_data->download_link;
		}

		$manifest = Jetpack_Beta::get_beta_manifest();

		if ( 'master' === $section && isset( $manifest->{$section}->download_url ) ) {
			return $manifest->{$section}->download_url;
		}

		if ( isset( $manifest->{$section}->{$branch}->download_url ) ) {
			return $manifest->{$section}->{$branch}->download_url;
		}

		return null;
	}

	static function get_jetpack_plugin_info() {
		if( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$plugin_file_path = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::get_plugin_file();
		if ( file_exists( $plugin_file_path ) ) {
			return get_plugin_data( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::get_plugin_file() );
		}

		return null;
	}

	/*
	 * This needs to happen on shutdown. Other wise it doesn't work.
	 */
	static function switch_active() {
		self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE );
	}
	
	static function get_beta_manifest() {
		return self::get_remote_data( JETPACK_BETA_MANIFEST_URL, 'manifest' );
	}

	static function get_org_data() {
		return self::get_remote_data( JETPACK_ORG_API_URL, 'org_data' );
	}

	static function get_remote_data( $url, $transient ) {
		$prefix = 'jetpack_beta_';
		$cache  = get_site_transient( $prefix . $transient );
		if ( $cache ) {
			return $cache;
		}

		$remote_manifest = wp_remote_get( $url );

		if ( is_wp_error( $remote_manifest ) ) {
			return false;
		}

		$cache = json_decode( wp_remote_retrieve_body( $remote_manifest ) );
		set_site_transient( $prefix . $transient, $cache, MINUTE_IN_SECONDS * 15 );

		return $cache;
	}

	function auto_update_jetpack_beta( $update, $item ) {
		if ( 'sure' !== get_option( 'jp_beta_autoupdate' ) ) {
			return $update;
		}

		// Array of plugin slugs to always auto-update
		$plugins = array(
			JETPACK_DEV_PLUGIN_FILE,
		);
		if ( in_array( $item->slug, $plugins ) ) {
			return true; // Always update plugins in this array
		} else {
			return $update; // Else, use the normal API response to decide whether to update or not
		}
	}

	static function install_and_activate( $branch, $section ) {
		if ( 'stable' === $section && file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . JETPACK_PLUGIN_FILE ) ) {
			self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE, true );
			self::update_option( $branch, $section );
			return;
		}

		if ( self::get_branch_and_section_dev() === array( $branch, $section )
		     && file_exists( WP_PLUGIN_DIR . '/' . JETPACK_DEV_PLUGIN_FILE ) ) {
			self::replace_active_plugin( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE, true );
			self::update_option( $branch, $section );
			return;
		}

		self::proceed_to_install( self::get_install_url( $branch, $section ), self::get_plugin_slug( $section ), $section );
		self::update_option( $branch, $section );
		return;
	}

	static function update_option( $branch, $section ) {
		if ( 'stable' !== $section ) {
			update_option( self::$option_dev_installed, array( $branch, $section, self::get_manifest_data( $branch, $section ) ) );
		}
		update_option( self::$option, array( $branch, $section) );
	}

	static function get_manifest_data( $branch, $section ) {
		$installed = get_option( self::$option_dev_installed );
		$current_manifest_data = isset( $installed[2] ) ? $installed[2] : false;

		$manifest_data = self::get_beta_manifest();

		if ( ! isset( $manifest_data->{$section} ) ) {
			return $current_manifest_data;
		}

		if ( 'master' === $section ) {
			return $manifest_data->{$section};
		}

		if ( isset( $manifest_data->{$section}->{$branch} ) ) {
			return $manifest_data->{$section}->{$branch};
		}

		return $current_manifest_data;
	}

	static function proceed_to_install( $url, $plugin_folder = JETPACK_DEV_PLUGIN_SLUG, $section ) {
		$temp_path = download_url( $url );

		if ( is_wp_error( $temp_path ) ) {
			wp_die( sprintf( __( 'Error Downloading: <a href="%1$s">%1$s</a> - Error: %2$s', 'jetpack-beta' ), $url, $temp_path->get_error_message() ) );
		}

		$creds = request_filesystem_credentials( site_url() . '/wp-admin/', '', false, false, array() );
		/* initialize the API */
		if ( ! WP_Filesystem( $creds ) ) {
			/* any problems and we exit */
			wp_die( "Jetpack Beta: No File System access" );
		}

		global $wp_filesystem;
		if ( 'stable' === $section ) {
			$plugin_path = WP_PLUGIN_DIR;
		} else {
			$plugin_path = str_replace( ABSPATH, $wp_filesystem->abspath(), WP_PLUGIN_DIR  );
		}

		$result = unzip_file( $temp_path, $plugin_path );

		if ( is_wp_error( $result ) ) {
			wp_die( sprintf( __( 'Error Unziping file: Error: %1$s', 'jetpack-beta' ), $result->get_error_message() ) );
		}

		if ( 'stable' === $section ) {
			self::replace_active_plugin( JETPACK_DEV_PLUGIN_FILE, JETPACK_PLUGIN_FILE, true );
		} else {
			self::replace_active_plugin( JETPACK_PLUGIN_FILE, JETPACK_DEV_PLUGIN_FILE, true );
		}

	}

	static function is_network_active() {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			return false;
		}

		if ( is_plugin_active_for_network( JETPACK_PLUGIN_FILE ) || is_plugin_active_for_network( JETPACK_DEV_PLUGIN_FILE ) ){
			return true;
		}

		return false;
	}

	static function replace_active_plugin( $current_plugin, $replace_with_plugin, $force_activate = false ) {
		if ( self::is_network_active() ) {
			$new_active_plugins = array();
			$network_active_plugins = get_site_option( 'active_sitewide_plugins' );
			foreach ( $network_active_plugins as $plugin => $date ) {
				$key = ( $plugin === $current_plugin ? $replace_with_plugin : $plugin );
				$new_active_plugins[ $key ] = $date;
			}
			update_site_option( 'active_sitewide_plugins', $new_active_plugins );
			return;
		}

		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$new_active_plugins = array();

		foreach ( $active_plugins as $plugin ) {
			$new_active_plugins[] = ( $plugin === $current_plugin ? $replace_with_plugin : $plugin );
		}

		if ( $force_activate && ! in_array( $replace_with_plugin, $new_active_plugins ) ) {
			$new_active_plugins[] = $replace_with_plugin;
		}
		update_option( 'active_plugins', $new_active_plugins );
	}
}

register_activation_hook( __FILE__, array( 'Jetpack_Beta', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Jetpack_Beta', 'deactivate' ) );

add_action( 'init', array( 'Jetpack_Beta', 'instance' ) );
add_action( 'muplugins_loaded', array( 'Jetpack_Beta', 'is_network_enabled' ) );



