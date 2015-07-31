<?php
/*
Plugin Name: Unbounce
Plugin URI: http://unbounce.com
Description: Publish Unbounce Landing Pages to your Wordpress Domain.
Version: 0.1.21
Author: Unbounce
Author URI: http://unbounce.com
License: GPLv2
*/

require_once dirname(__FILE__) . '/UBCompatibility.php';
require_once dirname(__FILE__) . '/UBUtil.php';
require_once dirname(__FILE__) . '/UBConfig.php';
require_once dirname(__FILE__) . '/UBLogger.php';
require_once dirname(__FILE__) . '/UBHTTP.php';
require_once dirname(__FILE__) . '/UBIcon.php';

register_activation_hook(__FILE__, function() {
  add_option(UBConfig::UB_ROUTES_CACHE_KEY, array());
  add_option(UBConfig::UB_REMOTE_DEBUG_KEY, 0);
  add_option(UBConfig::UB_PAGE_SERVER_DOMAIN_KEY,
             UBConfig::default_page_server_domain());
  add_option(UBConfig::UB_REMOTE_LOG_URL_KEY,
             UBConfig::default_remote_log_url());
  add_option(UBConfig::UB_API_URL_KEY,
             UBConfig::default_api_url());
  add_option(UBConfig::UB_API_CLIENT_ID_KEY,
             UBConfig::default_api_client_id());
  add_option(UBConfig::UB_AUTHORIZED_DOMAINS_KEY,
             UBConfig::default_authorized_domains());
});

register_deactivation_hook(__FILE__, function() {
  delete_option(UBConfig::UB_ROUTES_CACHE_KEY);
  delete_option(UBConfig::UB_REMOTE_DEBUG_KEY);
  delete_option(UBConfig::UB_PAGE_SERVER_DOMAIN_KEY);
  delete_option(UBConfig::UB_REMOTE_LOG_URL_KEY);
  delete_option(UBConfig::UB_API_URL_KEY);
  delete_option(UBConfig::UB_API_CLIENT_ID_KEY);
  delete_option(UBConfig::UB_AUTHORIZED_DOMAINS_KEY);
});

add_action('init', function() {
  UBLogger::setup_logger();

  $domain = UBUtil::array_fetch($_SERVER, 'HTTP_HOST');

  if(!UBConfig::is_authorized_domain($domain)) {
    UBLogger::info("Domain: $domain has not been authorized");
    return;
  }

  $start = microtime(true);

  $ps_domain = UBConfig::page_server_domain();
  $http_method = UBUtil::array_fetch($_SERVER, 'REQUEST_METHOD');
  $referer = UBUtil::array_fetch($_SERVER, 'HTTP_REFERER');
  $user_agent = UBUtil::array_fetch($_SERVER, 'HTTP_USER_AGENT');
  $protocol = UBHTTP::determine_protocol($_SERVER, is_ssl());
  $current_path = UBUtil::array_fetch($_SERVER, 'REQUEST_URI');

  $raw_url = $protocol . $ps_domain . $current_path;
  $current_url  = $protocol . $domain . $current_path;

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
  UBLogger::debug_var('current_url', $current_url );

  ////////////////////

  if ($proxyable_url_set == null) {
    UBLogger::warning("sitemap.xml not found for domain " . $domain);
  }
  else {
    $url_purpose = UBHTTP::get_url_purpose($proxyable_url_set,
                                           $http_method,
                                           $current_url);
    if ($url_purpose == null) {
      UBLogger::debug("ignoring request to URL " . $current_url);
    }
    elseif ($url_purpose == 'HealthCheck') {
      header('Content-Type: application/json');
      $version = UBConfig::UB_VERSION;
      echo "{\"ub_wordpress\":{\"version\":\"$version\"}}";
      exit(0);
    }
    else {
      // Disable caching plugins. This should take care of:
      //   - W3 Total Cache
      //   - WP Super Cache
      //   - ZenCache (Previously QuickCache)
      if(!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
      }

      // Disable CDN for W3 Total Cache
      if(!defined('DONOTCDN')) {
        define('DONOTCDN', true);
      }

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

add_action('admin_init', function() {
  # disable incompatible scripts

  # WPML
  wp_dequeue_script('installer-admin');

  # enqueue our own scripts
  wp_enqueue_script('ub-rx',
                    plugins_url('js/rx.lite.compat.min.js', __FILE__));
  wp_enqueue_script('set-unbounce-domains-js',
                    plugins_url('js/set-unbounce-domains.js', __FILE__),
                    array('jquery', 'ub-rx'));

  # re-enable incompatible scripts

  # WPML
  wp_enqueue_script('installer-admin');
}, 0);

function render_unbounce_pages($domain_info, $domain) {
  echo '<h1>Unbounce Pages</h1>';

  if(UBConfig::is_authorized_domain($domain)) {
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

    $flush_pages_url = admin_url('admin-post.php');
    echo "<form method='get' action='$flush_pages_url'>";
    echo '<input type="hidden" name="action" value="flush_unbounce_pages" />';
    echo get_submit_button('Refresh Cache', 'secondary', 'flush-unbounce-pages', true);
    echo '</form>';

    echo '<p><a href="https://app.unbounce.com">Go to Unbounce</a></p>';
  } else {
    echo '<div class="error">This domain has not been authorized with Unbounce.</div>';
  }

  $set_domains_url = admin_url('admin-post.php?action=set_unbounce_domains');
  echo "<form method='post' action='$set_domains_url'>";
  echo "<input type='hidden' name='domains' />";
  echo get_submit_button('Authorize With Unbounce',
                         'primary',
                         'set-unbounce-domains',
                         true,
                         array('data-set-domains-url' => $set_domains_url,
                               'data-redirect-uri' => admin_url('admin.php?page=unbounce-pages'),
                               'data-api-url' => UBConfig::api_url(),
                               'data-api-client-id' => UBConfig::api_client_id()));
  echo '</form>';

  $authorization = UBUtil::array_fetch($_GET, 'authorization');
  if($authorization === 'success') {
    echo '<div class="updated">Successfully authorized with Unbounce.</div>';
  } elseif($authorization === 'failure') {
    echo '<div class="error">Sorry, there was an error authorizing with Unbounce. Please try again.</div>';
  }
}

add_action('admin_menu', function() {
  $print_admin_panel = function() {
    $domain = UBUtil::array_fetch($_SERVER, 'HTTP_HOST');
    $domain_info  = UBConfig::read_unbounce_domain_info($domain, false);

    render_unbounce_pages($domain_info, $domain);
  };

  add_menu_page('Unbounce Pages',
                'Unbounce Pages',
                'manage_options',
                'unbounce-pages',
                $print_admin_panel,
                UBIcon::base64_encoded_svg());
});

add_action('admin_post_set_unbounce_domains', function() {
  $domains_json = UBUtil::array_fetch($_POST, 'domains', '');
  $domains = explode(',', $domains_json);

  if($domains_json && is_array($domains)) {
    update_option(UBConfig::UB_AUTHORIZED_DOMAINS_KEY, $domains);
    $authorization = 'success';
  } else {
    $authorization = 'failure';
  }

  status_header(301);
  $location = admin_url("admin.php?page=unbounce-pages&authorization=$authorization");
  header("Location: $location");
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
  UBLogger::upload_logs_to_unbounce(get_option(UBConfig::UB_REMOTE_LOG_URL_KEY));
});

?>
