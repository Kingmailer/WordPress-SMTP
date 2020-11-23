<?php

	/*
	 * kingmailer-wordpress-plugin - Sending mail from Wordpress using Kingmailer
	 * Copyright (C) 2020 Krishna Moniz
	 * Copyright (C) 2016 Mailgun, et al.
	 *
	 * This program is free software; you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation; either version 2 of the License, or
	 * (at your option) any later version.
	 *
	 * This program is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 * GNU General Public License for more details.
	 *
	 * You should have received a copy of the GNU General Public License along
	 * with this program; if not, write to the Free Software Foundation, Inc.,
	 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
	 */

	class KingmailerAdmin extends Kingmailer
	{
		/**
		 * @var    array    Array of "safe" option defaults.
		 */
		private $defaults;

		/**
		 * Setup backend functionality in WordPress.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function __construct()
		{
			Kingmailer::__construct();

			// Load localizations if available
			load_plugin_textdomain('kingmailer', false, 'kingmailer/languages');

			// Activation hook
			register_activation_hook($this->plugin_file, array(&$this, 'init'));

			if( !defined('KINGMAILER_USEAPI') || !KINGMAILER_USEAPI ):

				// Hook into admin_init and register settings and potentially register an admin_notice
				add_action('admin_init', array(&$this, 'admin_init'));

				// Activate the options page
				add_action('admin_menu', array(&$this, 'admin_menu'));

				// Register an AJAX action for testing mail sending capabilities
				add_action('wp_ajax_kingmailer-test', array(&$this, 'ajax_send_test'));
			endif;
		}

		/**
		 * Initialize the default options during plugin activation.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function init()
		{
			// Define kingmailer default values
			$this->defaults = array(
				'domain' => '',
				'api_host' => 'kingmailer.org',
				'use_api' => '1',
				'api_key' => '',
				'host' => 'kingmailer.org',
				'username' => '',
				'password' => '',
				'secure' => '1',
				// 'sectype' => 'tls',
				// 'track-clicks' => '',
				// 'track-opens' => '',
				// 'campaign-id' => '',
				'override-from' => '0'
			);
			
			// Use the default values if no user values are set
			if (!$this->options){
				$this->options = $this->defaults;
				add_option('kingmailer', $this->options);
			}
		}

		/**
		 * Add the options page.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function admin_menu()
		{
			if (current_user_can('manage_options')):

				$this->hook_suffix = add_options_page(__('Kingmailer', 'kingmailer'), __('Kingmailer', 'kingmailer'),
					'manage_options', 'kingmailer', array(&$this, 'options_page'));
				add_action("admin_print_scripts-{$this->hook_suffix}", array(&$this, 'admin_js'));
				add_filter("plugin_action_links_{$this->plugin_basename}", array(&$this, 'filter_plugin_actions'));
			endif;
		}

		/**
		 * Enqueue javascript required for the admin settings page.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function admin_js()
		{
			$script_version = defined('KM_PLUGIN_VER') ? KM_PLUGIN_VER : '';
			$translation_array = array(
				'test_confirmation' => __('You are trying to send a test mail without saving your changes, i.e. your test mail will use the previous settings.\n\nClick "Cancel" and then "Save Changes" if you wish to save your changes before sending the test mail.', 'kingmailer'),
				'test_testing' => __('Testing...', 'kingmailer'),
				'test_failed' => __('Failure', 'kingmailer'),
				'test_send_mail' => __('Send Test Mail', 'kingmailer'),
			);
			$ajax_array = array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'ajax_nonce' => wp_create_nonce(),
			  );

			// Add the translations	and AJAX info
			$url = plugins_url('/js/admin.js', $this->plugin_file  );

			wp_enqueue_script( 'km_admin_js', plugins_url('/js/admin.js', $this->plugin_file  ),  array( 'jquery' ), $script_version, false );
			wp_localize_script('km_admin_js', 'km_admin_js_i18n', $translation_array);
			wp_localize_script('km_admin_js', 'km_admin_js_ajax', $ajax_array );
		}

		/**
		 * Add a settings link to the plugin actions.
		 *
		 * @param    array $links Array of the plugin action links
		 *
		 * @return    array
		 *
		 * @since    0.1
		 */
		public function filter_plugin_actions($links)
		{
			$settings_link = '<a href="' . menu_page_url('kingmailer', false) . '">' . __('Settings', 'kingmailer') . '</a>';
			array_unshift($links, $settings_link);

			return $links;
		}

		/**
		 * Output the options page.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function options_page()
		{
			if (!@include 'options-page.php'):
				printf(__('<div id="message" class="updated fade"><p>The options page for the <strong>Kingmailer</strong> plugin cannot be displayed. The file <strong>%s</strong> is missing.  Please reinstall the plugin.</p></div>',
					'kingmailer'), dirname(__FILE__) . '/options-page.php');
			endif;
		}

		/**
		 * Wrapper function hooked into admin_init to register settings
		 * and potentially register an admin notice if the plugin hasn't
		 * been configured yet.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function admin_init()
		{
			$this->register_settings();
			$api_key = $this->get_option('api_key');
			$use_api = $this->get_option('use_api');
			$password = $this->get_option('password');

			add_action('admin_notices', array(&$this, 'admin_notices'));
		}

		/**
		 * Whitelist the kingmailer options.
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function register_settings()
		{
			register_setting('kingmailer', 'kingmailer', array(&$this, 'validation'));
		}

		/**
		 * Data validation callback function for options.
		 *
		 * @param    array $options An array of options posted from the options page
		 *
		 * @return    array
		 *
		 * @since    0.1
		 */
		public function validation($options)
		{
			$api_key = trim($options[ 'api_key' ]);
			$username = trim($options[ 'username' ]);
			if (!empty($api_key)):
				$pos = strpos($api_key, 'api:');
				if ($pos !== false && $pos == 0):
					$api_key = substr($api_key, 4);
				endif;
				$options[ 'api_key' ] = $api_key;
			endif;

			foreach ($options as $key => $value) {
				$options[ $key ] = trim($value);
			}

			if (empty($options[ 'override-from' ])):
				$options[ 'override-from' ] = $this->defaults[ 'override-from' ];
			endif;

			if (empty($options[ 'sectype' ])):
				$options[ 'sectype' ] = $this->defaults[ 'sectype' ];
			endif;

			$this->options = $options;

			return $options;
		}

		/**
		 * Function to output an admin notice
		 * when plugin settings or constants need to be configured
		 *
		 * @return    void
		 *
		 * @since    0.1
		 */
		public function admin_notices()
		{
			$screen = get_current_screen();
			if (!current_user_can('manage_options') || $screen->id == $this->hook_suffix):
				return;
			endif;

			$smtpPasswordUndefined = ( !$this->get_option('password') && ( !defined('KINGMAILER_PASSWORD') || !KINGMAILER_PASSWORD ) );
			$smtpActiveNotConfigured = ( $this->get_option('use_api') === '0' && $smtpPasswordUndefined );
			$apiKeyUndefined = ( !$this->get_option('api_key') && ( !defined('KINGMAILER_APIKEY') || !KINGMAILER_APIKEY ));
			$apiActiveNotConfigured = ( $this->get_option('use_api') === '1' && $apiKeyUndefined  );

			if ($apiActiveNotConfigured || $smtpActiveNotConfigured):
				echo ('<div id="kingmailer-warning" class="notice notice-warning is-dismissible"><p>');
				printf(
					__('Kingmailer is not properly configured! You can configure your Kingmailer settings in your wp-config.php file or <a href="%1$s">here</a>',
						'kingmailer'),
					menu_page_url('kingmailer', false)
				);
				echo ('</p></div>');
			endif;

			if ($this->get_option('override-from') === '1' && (!$this->get_option('from-name') || !$this->get_option('from-address'))):
				echo ('<div id="kingmailer-warning" class="notice notice-warning is-dismissible"><p><strong>');
				_e('Kingmailer is almost ready. ', 'kingmailer');
				echo ('</strong></p></div>');
			endif;
		}

		/**
		 * AJAX callback function to test mail sending functionality.
		 *
		 * @return    string
		 *
		 * @since    0.1
		 */
		public function ajax_send_test()
		{
			nocache_headers();
			header('Content-Type: application/json');

			if (!current_user_can('manage_options') || !wp_verify_nonce($_GET[ '_wpnonce' ])):
				die(
				json_encode(
					array(
						'message' => __('Unauthorized', 'kingmailer'),
						'method' => null,
						'error' => __('Unauthorized', 'kingmailer'),
					)
				)
				);
			endif;

			$use_api = (defined('KINGMAILER_USEAPI') && KINGMAILER_USEAPI) ? KINGMAILER_USEAPI : $this->get_option('use_api');
			$secure = (defined('KINGMAILER_SECURE') && KINGMAILER_SECURE) ? KINGMAILER_SECURE : $this->get_option('secure');
			$sectype = (defined('KINGMAILER_SECTYPE') && KINGMAILER_SECTYPE) ? KINGMAILER_SECTYPE : $this->get_option('sectype');

			if ((bool) $use_api):
				$method = __('HTTP API', 'kingmailer');
			else:
				$method = ((bool) $secure) ? __('Secure SMTP', 'kingmailer') : __('Insecure SMTP', 'kingmailer');
				if ((bool) $secure):
					$method = $method . sprintf(__(' via %s', 'kingmailer'), $sectype);
				endif;
			endif;

			$admin_email = get_option('admin_email');
			$result = wp_mail(
				$admin_email,
				__('Kingmailer WordPress Plugin Test', 'kingmailer'),
				sprintf(__("This is a test email generated by the Kingmailer WordPress plugin.\n\nIf you have received this message, the requested test has succeeded.\n\nThe method used to send this email was: %s.",
					'kingmailer'), $method),
				array('Content-Type: text/plain')
			);




			

			// if ((bool) $use_api):
			// 	if (!function_exists('km_api_last_error')):
			// 		if (!include dirname(__FILE__) . '/wp-mail-api.php'):
			// 			self::deactivate_and_die(dirname(__FILE__) . '/wp-mail-api.php');
			// 		endif;
			// 	endif;

			// 	$error_msg = km_api_last_error();
			// else:
			// 	if (!function_exists('km_smtp_last_error')):
			// 		if (!include dirname(__FILE__) . '/wp-mail-smtp.php'):
			// 			self::deactivate_and_die(dirname(__FILE__) . '/wp-mail-smtp.php');
			// 		endif;
			// 	endif;

			// 	$error_msg = km_smtp_last_error();
			// endif;

			if ($result):
				die(
				json_encode(
					array(
						'message' => __('Success', 'kingmailer'),
						'method' => $method,
						'error' => __('Success', 'kingmailer'),
					)
				)
				);
			else:
				die(
				json_encode(
					array(
						'message' => __('Failure', 'kingmailer'),
						'method' => $method,
						'error' => $error_msg,
					)
				)
				);
			endif;
		}
	}
