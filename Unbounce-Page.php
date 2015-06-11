<?php
/*
Plugin Name: Unbounce
Plugin URI: http://unbounce.com
Description: Publish Unbounce Landing Pages to your Wordpress Domain.
Version: 0.1.9
Author: Unbounce
Author URI: http://unbounce.com
License: GPLv2
*/

require_once dirname(__FILE__) . '/UBUtil.php';
require_once dirname(__FILE__) . '/UBConfig.php';
require_once dirname(__FILE__) . '/UBLogger.php';
require_once dirname(__FILE__) . '/UBHTTP.php';
require_once dirname(__FILE__). '/UBIcon.php';

register_activation_hook(__FILE__, function() {
  add_option(UBConfig::UB_ROUTES_CACHE_KEY, array());
  add_option(UBConfig::UB_REMOTE_DEBUG_KEY, 0);
});

register_deactivation_hook(__FILE__, function() {
  delete_option(UBConfig::UB_ROUTES_CACHE_KEY);
  delete_option(UBConfig::UB_REMOTE_DEBUG_KEY);
});

add_action('init', function() {
  UBLogger::setup_logger();

  $start = microtime(true);

  $ps_domain = UBConfig::get_page_server_domain();
  $http_method = UBUtil::array_fetch($_SERVER, 'REQUEST_METHOD');
  $referer = UBUtil::array_fetch($_SERVER, 'HTTP_REFERER');
  $user_agent = UBUtil::array_fetch($_SERVER, 'HTTP_USER_AGENT');
  $protocol = UBHTTP::determine_protocol($_SERVER, is_ssl());
  $domain = UBUtil::array_fetch($_SERVER, 'HTTP_HOST');
  $current_path = UBUtil::array_fetch($_SERVER, 'REQUEST_URI');

  $raw_url = $protocol . $ps_domain . $current_path;
  $current_url  = trim($protocol . $domain . $current_path, '/');

  $domain_info = UBConfig::read_unbounce_domain_info($domain, false);
  $proxyable_url_set = UBUtil::array_fetch($domain_info, 'proxyable_url_set', array());

  UBLogger::debug_var('ps_domain', $ps_domain);
  UBLogger::debug_var('http_method', $http_method);
  UBLogger::debug_var('referer', $referer);
  UBLogger::debug_var('user_agent', $user_agent);
  UBLogger::debug_var('protocol', $protocol);
  UBLogger::debug_var('domain', $domain);
  UBLogger::debug_var('current_path', $current_path);
  UBLogger::debug_var('raw_url', $raw_url);
  UBLogger::debug_var('current_url ', $current_url );

  ////////////////////

  if ($proxyable_url_set == null) {
    UBLogger::warning("wp-routes.json not found for domain " . $domain);
  }
  else {
    $url_purpose = UBHTTP::get_url_purpose($proxyable_url_set,
                                           $http_method,
                                           $current_url);
    if ($url_purpose == null) {
      UBLogger::debug("ignoring request to URL " . $current_url);
    }
    else {
      UBLogger::debug("perform ''" . $url_purpose . "'' on received URL " . $current_url);

      $cookies_to_forward = UBUtil::array_select_by_key($_COOKIE,
                                                        array('ubvs', 'ubpv', 'ubvt'));

      $cookie_string = UBHTTP::cookie_string_from_array($cookies_to_forward);

      $req_headers = $referer == null ? array('Host: ' . $domain) : array('Referer: ' . $referer, 'Host: ' . $domain);

      // Make sure we don't get cached by Wordpress hosts like WPEngine
      header('Cache-Control: max-age=0; private');

      UBHTTP::stream_request($http_method,
                             $raw_url,
                             $cookie_string,
                             $req_headers,
                             $_POST,
                             $user_agent);

      $end = microtime(true);
      $time_taken = ($end - $start) * 1000;

      UBLogger::debug_var('time_taken', $time_taken);
      UBLogger::debug("proxying for $current_url done successfuly -- took $time_taken ms");

      exit(0);
    }
  }
});

function render_unbounce_pages($domain_info) {
  echo '<h1>Unbounce Pages</h1>';

  $proxyable_url_set = UBUtil::array_fetch($domain_info, 'proxyable_url_set');
  if(empty($proxyable_url_set)) {
    echo '<p class="warning">No URLs have been registered from Unbounce</p>';

  } else {
    $proxyable_url_set_fetched_at = UBUtil::array_fetch($domain_info, 'proxyable_url_set_fetched_at');

    $list_items = array_map(function($url) { return '<li><a href="//'. $url .'">' . $url . '</a></li>'; },
                            $proxyable_url_set);

    echo '<div class="unbounce-page-list">';
    echo '<ul>' . join($list_items, "\n") . '</ul>';
    echo '<p>Last refresh date: <span id="last-cache-fetch" style="font-weight: bold;">' . date('r', $proxyable_url_set_fetched_at) . '</span></p>';
    echo '</div>';

  }

  $flush_pages_url = admin_url('admin-post.php?action=flush_unbounce_pages');
  echo "<p><a href='$flush_pages_url'>Refresh Cache</a></p>";
  echo '<p><a href="https://app.unbounce.com">Go to Unbounce</a></p>';
}

add_action('admin_menu', function() {
  $print_admin_panel = function() {
    $domain = UBUtil::array_fetch($_SERVER, 'HTTP_HOST');
    $domain_info  = UBConfig::read_unbounce_domain_info($domain, false);
    render_unbounce_pages($domain_info);
  };

  add_menu_page('Unbounce Pages',
                'Unbounce Pages',
                'manage_options',
                'unbounce-pages',
                $print_admin_panel,
                UBIcon::base64_encoded_svg());
});

add_action('admin_post_flush_unbounce_pages', function() {
  $domain = UBUtil::array_fetch($_SERVER, 'HTTP_HOST');
  // Expire cache and redirect
  $_domain_info = UBConfig::read_unbounce_domain_info($domain, true);
  status_header(301);
  $location = admin_url('admin.php?page=unbounce-pages');
  header("Location: $location");
});

add_action('shutdown', function() {
  UBLogger::upload_logs_to_unbounce(UBConfig::get_remote_log_url());
});

?>
