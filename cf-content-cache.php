<?php
/*
Plugin Name: CF Content Cache 
Plugin URI:  
Description: Cache post/page content for a configurable time limit. 
Version: 1.1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

define('CF_CONTENT_CACHE_LOG', false);

load_plugin_textdomain('cf-content-cache');

$cfcc_the_content_filters = null;

function cfcc_transient_key($post_id = null) {
	if (empty($post_id)) {
		global $post;
		$post_id = $post->ID;
	}
	return 'cf_content_cache_'.$post_id;
}

function cfcc_the_content_placebo($content) {
	return $content;
}
add_filter('the_content', 'cfcc_the_content_placebo', 0);

function cfcc_the_content_reset_filters($post) {
	global $wp_filter, $cfcc_the_content_filters;
	if (!is_null($cfcc_the_content_filters)) {
		$wp_filter['the_content'] = $cfcc_the_content_filters;
	}
}
add_action('the_post', 'cfcc_the_content_reset_filters');

function cfcc_the_content_get_cache($content) {
	global $wp_filter, $cfcc_the_content_filters;
// check transient
	if ($cache = get_transient(cfcc_transient_key())) {
		if (CF_CONTENT_CACHE_LOG && !function_exists('wpcom_is_vip')) {
			error_log('using transient cache for '.cfcc_transient_key());
		}
// don't run any more filters
		$cfcc_the_content_filters = $wp_filter['the_content'];
		$wp_filter['the_content'] = array();
		return $cache;
	}
	if (CF_CONTENT_CACHE_LOG && !function_exists('wpcom_is_vip')) {
		error_log('not using transient cache for '.cfcc_transient_key());
	}
// check highest # priority filter, run after to catch fully formatted content
	global $wp_filter;
	$priorities = array_keys($wp_filter['the_content']);
	sort($priorities);
	add_filter('the_content', 'cfcc_the_content_set_cache', $priorities[count($priorities) - 1] + 1);
	return $content;
}
add_filter('the_content', 'cfcc_the_content_get_cache', 0);

function cfcc_the_content_set_cache($content) {
// cache data in transient
	set_transient(cfcc_transient_key(), $content, cfcc_setting('cfcc_cache_seconds'));
	return $content;
}

function cfcc_save_post($post_id) {
	delete_transient(cfcc_transient_key($post_id));
}
add_action('save_post', 'cfcc_save_post');

function cfcc_request_handler() {
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'cfcc_update_settings':
				check_admin_referer('cfcc_update_settings');
				cfcc_update_settings();
				wp_redirect(admin_url('options-general.php?page='.basename(__FILE__).'&updated=true'));
				die();
				break;
		}
	}
}
add_action('init', 'cfcc_request_handler');

$cfcc_settings = array(
	'cfcc_cache_seconds' => array(
		'type' => 'int',
		'label' => 'Cache post content for how many seconds?',
		'default' => '3600',
		'help' => 'Example: 1 hour = 3600, 3 hours = 10800. Minimum = 600.',
		'minimum' => '600'
	),
);

function cfcc_setting($option) {
	$value = get_option($option);
	if (empty($value)) {
		global $cfcc_settings;
		$value = $cfcc_settings[$option]['default'];
	}
	return $value;
}

function cfcc_admin_menu() {
	add_options_page(
		__('CF Content Cache Settings', 'cf-content-cache'),
		__('CF Content Cache', 'cf-content-cache'),
		'manage_options',
		basename(__FILE__),
		'cfcc_settings_form'
	);
}
add_action('admin_menu', 'cfcc_admin_menu');

function cfcc_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if (basename($file) == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'cf-content-cache').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
add_filter('plugin_action_links', 'cfcc_plugin_action_links', 10, 2);

if (!function_exists('cf_settings_field')) {
	function cf_settings_field($key, $config) {
		$option = get_option($key);
		if (empty($option) && !empty($config['default'])) {
			$option = $config['default'];
		}
		$label = '<label for="'.$key.'">'.$config['label'].'</label>';
		$help = '<span class="help">'.$config['help'].'</span>';
		switch ($config['type']) {
			case 'select':
				$output = $label.'<select name="'.$key.'" id="'.$key.'">';
				foreach ($config['options'] as $val => $display) {
					$option == $val ? $sel = ' selected="selected"' : $sel = '';
					$output .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars($display).'</option>';
				}
				$output .= '</select>'.$help;
				break;
			case 'textarea':
				$output = $label.'<textarea name="'.$key.'" id="'.$key.'">'.htmlspecialchars($option).'</textarea>'.$help;
				break;
			case 'string':
			case 'int':
			default:
				$output = $label.'<input name="'.$key.'" id="'.$key.'" value="'.htmlspecialchars($option).'" />'.$help;
				break;
		}
		return '<div class="option">'.$output.'<div class="clear"></div></div>';
	}
}

function cfcc_settings_form() {
	global $cfcc_settings;

	print('
<style type="text/css">
fieldset.options div.option {
	background: #EAF3FA;
	margin-bottom: 8px;
	padding: 10px;
}
fieldset.options div.option label {
	display: block;
	float: left;
	font-weight: bold;
	margin-right: 10px;
	width: 150px;
}
fieldset.options div.option span.help {
	color: #666;
	font-size: 11px;
	margin-left: 8px;
}
</style>
<div class="wrap">
	<h2>'.__('CF Content Cache Settings', 'cf-content-cache').'</h2>
	<form id="cfcc_settings_form" name="cfcc_settings_form" action="'.admin_url('options-general.php').'" method="post">
		<input type="hidden" name="cf_action" value="cfcc_update_settings" />
		<fieldset class="options">
	');
	foreach ($cfcc_settings as $key => $config) {
		echo cf_settings_field($key, $config);
	}
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Save Settings', 'cf-content-cache').'" class="button-primary" />
		</p>
	');
	wp_nonce_field('cfcc_update_settings');
	print('
	</form>
</div>
	');
}

function cfcc_update_settings() {
	if (!current_user_can('manage_options')) {
		return;
	}
	global $cfcc_settings;
	foreach ($cfcc_settings as $key => $option) {
		$value = '';
		switch ($option['type']) {
			case 'int':
				$value = intval($_POST[$key]);
				if (intval($value) < intval($option['minimum'])) {
					$value = intval($option['minimum']);
				}
				break;
			case 'select':
				$test = stripslashes($_POST[$key]);
				if (isset($option['options'][$test])) {
					$value = $test;
				}
				break;
			case 'string':
			case 'textarea':
			default:
				$value = stripslashes($_POST[$key]);
				break;
		}
		update_option($key, $value);
	}
	if (function_exists('wpcom_is_vip')) {
		return;
	}
	/* Removing for VIP needs until a check is in place
	global $wpdb;
	$transients = $wpdb->get_col("
		SELECT option_name
		FROM $wpdb->options
		WHERE option_name LIKE '_transient_cf_content_cache_%'
	");
	if (count($transients)) {
		foreach ($transients as $transient) {
			delete_transient(str_replace('_transient_', '', $transient));
		}
	}
	*/
}

//a:23:{s:11:"plugin_name";s:16:"CF Content Cache";s:10:"plugin_uri";N;s:18:"plugin_description";s:54:"Cache post/page content for a configurable time limit.";s:14:"plugin_version";s:3:"1.0";s:6:"prefix";s:4:"cfcc";s:12:"localization";s:16:"cf-content-cache";s:14:"settings_title";s:25:"CF Content Cache Settings";s:13:"settings_link";s:16:"CF Content Cache";s:4:"init";b:0;s:7:"install";b:0;s:9:"post_edit";b:0;s:12:"comment_edit";b:0;s:6:"jquery";b:0;s:6:"wp_css";b:0;s:5:"wp_js";b:0;s:9:"admin_css";b:0;s:8:"admin_js";b:0;s:8:"meta_box";b:0;s:15:"request_handler";b:0;s:6:"snoopy";b:0;s:11:"setting_cat";b:0;s:14:"setting_author";b:0;s:11:"custom_urls";b:0;}

?>