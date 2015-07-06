<?php

class UBConfig {

  const UB_PLUGIN_NAME = 'ub-wordpress';
  const UB_ROUTES_CACHE_KEY = 'ub-route-cache';
  const UB_REMOTE_DEBUG_KEY = 'ub-remote-debug';
  const UB_CACHE_TIMEOUT_ENV_KEY = 'UB_WP_ROUTES_CACHE_EXP';
  const UB_USER_AGENT = 'Unbounce WP Plugin 0.1.18';
  const UB_VERSION = '0.1.18';

  public static function get_page_server_domain() {
    $domain = getenv('UB_PAGE_SERVER_DOMAIN');
    return $domain ? $domain : 'wp.unbounce.com';
  }

  public static function get_remote_log_url() {
    $url = getenv('UB_REMOTE_LOG_URL');
    if ($url == null) {
      return 'https://events-gateway.unbounce.com/events/wordpress_logs';
    }
    return $url;
  }

  public static function debug_loggging_enabled() {
    return WP_DEBUG || WP_DEBUG_LOG || UBConfig::remote_debug_logging_enabled();
  }

  public static function remote_debug_logging_enabled() {
    return get_option(UBConfig::UB_REMOTE_DEBUG_KEY, 0) == 1;
  }

  public static function fetch_proxyable_url_set($domain, $etag) {
    if(!$domain) {
      UBLogger::warning('Domain not provided, not fetching sitemap.xml');
      return array('FAILURE', null, null, null);
    }

    $url = 'https://' . UBConfig::get_page_server_domain() . '/sitemap.xml';
    $curl = curl_init();
    $curl_options = array(
      CURLOPT_URL => $url,
      CURLOPT_CUSTOMREQUEST => "GET",
      CURLOPT_HEADER => true,
      CURLOPT_USERAGENT => UBConfig::UB_USER_AGENT,
      CURLOPT_HTTPHEADER => array('Host: ' . $domain, 'If-None-Match: ' . $etag),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => 5
    );

    UBLogger::debug("Retrieving routes from '$url', etag: '$etag', host: '$domain'");

    curl_setopt_array($curl, $curl_options);
    $data = curl_exec($curl);

    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = null;

    // when having an CURL error, http_code is 0
    if ($http_code == 0) {
      $curl_error = curl_error($curl);
    }

    curl_close($curl);

    list($headers, $body) = array_pad(explode("\r\n\r\n", $data, 2), 2, null);

    $matches = array();
    $does_match = preg_match('/ETag: (\S+)/is', $headers, $matches);
    if ($does_match) {
      $etag = $matches[1];
    }

    $matches = array();
    $does_match = preg_match('/Cache-Control: max-age=(\S+)/is', $headers, $matches);
    if ($does_match) {
      $max_age = $matches[1];
    }

    if ($http_code == 200) {
      list($success, $result) = UBConfig::url_list_from_sitemap($body);

      if ($success) {
        UBLogger::debug("Retrieved new routes, HTTP code: '$http_code'");
        return array('NEW', $etag, $max_age, $result);
      }
      else {
        $errors = join(', ', $result);
        UBLogger::warning("An error occurred while processing routes, XML errors: '$errors'");
        return array('FAILURE', null, null, null);
      }
    }
    if ($http_code == 304) {
      UBLogger::debug("Routes have not changed, HTTP code: '$http_code'");
      return array('SAME', $etag, $max_age, null);
    }
    if ($http_code == 404) {
      UBLogger::debug("No routes to retrieve, HTTP code: '$http_code'");
      return array('NONE', null, null, null);
    }
    else {
      UBLogger::warning("An error occurred while retrieving routes; HTTP code: '$http_code'; Error: " . $curl_error);
      return array('FAILURE', null, null, null);
    }
  }

  public static function url_list_from_sitemap($string) {
    if (is_null($string)) {
      return array(false, array('input is null'));
    }

    $use_internal_errors = libxml_use_internal_errors(true);
    $sitemap = simplexml_load_string($string);

    if($sitemap) {
      libxml_use_internal_errors($use_internal_errors);
      $urls = array();

      // Valid XML that is not a valid sitemap.xml will be considered an empty sitemap.xml.
      // We have no easy way to tell the difference between the two.
      if (isset($sitemap->url)) {
        foreach ($sitemap->url as $sitemap_url) {
          if (isset($sitemap_url->loc)) {
            $url = (string) $sitemap_url->loc;
            // URLs come in with protocol and trailing slash, we need just host and path with no
            // trailing slash internally.
            $urls[] = parse_url($url, PHP_URL_HOST) . rtrim(parse_url($url, PHP_URL_PATH), '/');
          }
        }
      }

      return array(true, $urls);
    }
    else {
      $errors = libxml_get_errors();
      libxml_use_internal_errors($use_internal_errors);
      return array(false, $errors);
    }
  }

  public static function _read_unbounce_domain_info($cache_getter,
                                                    $cache_setter,
                                                    $fetch_proxyable_url_set,
                                                    $domain,
                                                    $expire_now=false) {

    $proxyable_url_set = null;

    $cache_max_time_default = 10;

    $domains_info = $cache_getter(UBConfig::UB_ROUTES_CACHE_KEY);
    $domain_info = UBUtil::array_fetch($domains_info, $domain, array());

    $proxyable_url_set = UBUtil::array_fetch($domain_info, 'proxyable_url_set');
    $proxyable_url_set_fetched_at = UBUtil::array_fetch($domain_info, 'proxyable_url_set_fetched_at');
    $proxyable_url_set_cache_timeout = UBUtil::array_fetch($domain_info, 'proxyable_url_set_cache_timeout');
    $proxyable_url_set_etag = UBUtil::array_fetch($domain_info, 'proxyable_url_set_etag');

    $cache_max_time = is_null($proxyable_url_set_cache_timeout) ? $cache_max_time_default : $proxyable_url_set_cache_timeout;

    $current_time = time();

    if ($expire_now ||
        is_null($proxyable_url_set) ||
        ($current_time - $proxyable_url_set_fetched_at > $cache_max_time)) {

      $result_array = call_user_func($fetch_proxyable_url_set, $domain, $proxyable_url_set_etag);

      list($routes_status, $etag, $max_age, $proxyable_url_set_new) = $result_array;

      if ($routes_status == 'NEW') {
        $domain_info['proxyable_url_set'] = $proxyable_url_set_new;
        $domain_info['proxyable_url_set_etag'] = $etag;
        $domain_info['proxyable_url_set_cache_timeout'] = $max_age;
      }
      elseif ($routes_status == 'SAME') {
        // Just extend the cache
        $domain_info['proxyable_url_set_cache_timeout'] = $max_age;
      }
      elseif ($routes_status == 'NONE') {
        $domain_info['proxyable_url_set'] = array();
        $domain_info['proxyable_url_set_etag'] = null;
      }
      elseif ($routes_status == 'FAILURE') {
        UBLogger::warning('Route fetching failed');
      }
      else {
        UBLogger::warning("Unknown response from route fetcher: '$routes_status'");
      }

      $domain_info['proxyable_url_set_fetched_at'] = $current_time;
      $domains_info[$domain] = $domain_info;
      $cache_setter(UBConfig::UB_ROUTES_CACHE_KEY, $domains_info);
    }


    return UBUtil::array_select_by_key($domain_info,
                                       array('proxyable_url_set',
                                             'proxyable_url_set_fetched_at'));
  }

  public static function read_unbounce_domain_info($domain, $expire_now) {
    return UBConfig::_read_unbounce_domain_info(
      'get_option',
      'update_option',
      'UBConfig::fetch_proxyable_url_set',
      $domain,
      $expire_now);
  }

}
?>
