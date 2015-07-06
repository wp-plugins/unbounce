<?php

class UBHTTP {
  public static $powered_by_header_regex  = '/^X-Powered-By: (.+)$/i';
  public static $form_confirmation_url_regex = '/(.+)\/[a-z]+-form_confirmation\.html/';
  public static $forward_headers = '/^(Content-Type:|Location:|ETag:|Last-Modified:|Link:|Content-Location:|Set-Cookie:|X-Server-Instance:|X-Unbounce-PageId:|X-Unbounce-Variant:|X-Unbounce-VisitorID:)/i';

  public static function is_private_ip_address($ip_address) {
    return !filter_var($ip_address,
                       FILTER_VALIDATE_IP,
                       FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE);
  }

  public static function cookie_string_from_array($cookies) {
    $join_cookie_values = function ($k, $v) { return $k . '=' . $v; };
    $cookie_strings = array_map($join_cookie_values,
                                array_keys($cookies),
                                $cookies);
    return join('; ', $cookie_strings);
  }

  private static function fetch_header_value_function($regex) {
    return function ($header_string) use ($regex) {
      $matches = array();
      preg_match($regex,
                 $header_string,
                 $matches);
      return $matches[1];
    };
  }

  public static function rewrite_x_powered_by_header($header_string, $existing_headers) {
    $fetch_powered_by_value = UBHTTP::fetch_header_value_function(UBHTTP::$powered_by_header_regex);

    $existing_powered_by = preg_grep(UBHTTP::$powered_by_header_regex,
                                     $existing_headers);

    $existing_powered_by = array_map($fetch_powered_by_value,
                                     $existing_powered_by);

    return 'X-Powered-By: ' .
                            join($existing_powered_by, ', ') . ', ' .
                            $fetch_powered_by_value($header_string);
  }

  public static function get_proxied_for_header($out_headers,
                                                $forwarded_for,
                                                $current_ip) {
    if($forwarded_for !== null && UBHTTP::is_private_ip_address($current_ip)) {
      $proxied_for = $forwarded_for;
    } else {
      $proxied_for = $current_ip;
    }

    $out_headers[] = 'X-Proxied-For: ' . $proxied_for;
    return $out_headers;
  }

  public static function stream_headers_function($existing_headers) {
    return function ($curl, $header_string) use ($existing_headers) {
      $http_status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

      http_response_code($http_status_code);

      if(preg_match(UBHTTP::$powered_by_header_regex, $header_string) == 1) {
        $result = UBHTTP::rewrite_x_powered_by_header($header_string, $existing_headers);
        header($result);

      } elseif (preg_match(UBHTTP::$forward_headers, $header_string)) {
        // false means don't replace the exsisting header
        header($header_string, false);
      }

      // We must show curl that we've processed every byte of the input header
      return strlen($header_string);
    };
  }

  public static function stream_response_function() {
    return function ($curl, $string) {
      // Stream the body to the client
      echo $string;

      // We must show curl that we've processed every byte of the input string
      return strlen($string);
    };
  }

  public static function determine_protocol($server_global, $wp_is_ssl) {
    $forwarded_proto = UBUtil::array_fetch($server_global, 'HTTP_X_FORWARDED_PROTO');
    $request_scheme = UBUtil::array_fetch($server_global, 'REQUEST_SCHEME');
    $script_uri = UBUtil::array_fetch($server_global, 'SCRIPT_URI');
    $script_uri_scheme = parse_url($script_uri, PHP_URL_SCHEME);
    $https = UBUtil::array_fetch($server_global, 'HTTPS', 'off');

    // X-Forwarded-Proto should be respected first, as it is what the end
    // user will see (if Wordpress is behind a load balancer).
    if(UBHTTP::is_valid_protocol($forwarded_proto)) {
      return $forwarded_proto . '://';
    }
    // Next use REQUEST_SCHEME, if it is available. This is the recommended way
    // to get the protocol, but it is not available on all hosts.
    elseif(UBHTTP::is_valid_protocol($request_scheme)) {
      return $request_scheme . '://';
    }
    // Next try to pull it out of the SCRIPT_URI. This is also not always available.
    elseif(UBHTTP::is_valid_protocol($script_uri_scheme)) {
      return $script_uri_scheme . '://';
    }
    // Wordpress' is_ssl() may return the correct boolean for http/https if
    // the site was setup properly.
    elseif($wp_is_ssl || !is_null($https) && $https !== 'off') {
      return 'https://';
    }
    // We default to http as most HTTPS sites will also have HTTP available.
    else {
      return 'http://';
    }
  }

  private static function is_valid_protocol($protocol) {
    return $protocol === 'http' || $protocol === 'https';
  }

  public static function stream_request($method,
                                        $target_url,
                                        $cookie_string,
                                        $headers0,
                                        $post_body = null,
                                        $user_agent) {

    $existing_headers = headers_list();
    $forwarded_for = UBUtil::array_fetch($_SERVER, 'HTTP_X_FORWARDED_FOR');
    $remote_ip = UBUtil::array_fetch($_SERVER, 'REMOTE_ADDR');

    $headers = UBHTTP::get_proxied_for_header($headers0,
                                              $forwarded_for,
                                              $remote_ip);

    UBLogger::debug_var('target_url', $target_url);

    $stream_headers = UBHTTP::stream_headers_function($existing_headers);
    $stream_body = UBHTTP::stream_response_function();
    $curl = curl_init();
    // http://php.net/manual/en/function.curl-setopt.php
    $curl_options = array(
      CURLOPT_URL => $target_url,
      CURLOPT_POST => $method == "POST",
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_USERAGENT => $user_agent,
      CURLOPT_COOKIE => $cookie_string,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_HEADERFUNCTION => $stream_headers,
      CURLOPT_WRITEFUNCTION => $stream_body,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_TIMEOUT => 5
    );

    if ($method == "POST" && $post_body != null) {
      $curl_options[CURLOPT_POSTFIELDS] = http_build_query($post_body);
    }

    curl_setopt_array($curl, $curl_options);
    $resp = curl_exec($curl);
    if(!$resp){
      $message = 'Error proxying to "' . $target_url . ", " . $original_target_url
               . '": "' . curl_error($curl) . '" - Code: ' . curl_errno($curl);
      UBLogger::warning($message);
      http_response_code(500);
    }
    curl_close($curl);
  }

  public static function is_extract_url_proxyable($proxyable_url_set,
                                                  $extract_regex,
                                                  $match_position,
                                                  $url) {
    $matches = array();
    $does_match = preg_match($extract_regex,
                             $url,
                             $matches);

    return $does_match && in_array($matches[1], $proxyable_url_set);
  }

  public static function is_confirmation_dialog($proxyable_url_set, $url_without_protocol) {
    return UBHTTP::is_extract_url_proxyable($proxyable_url_set,
                                            UBHTTP::$form_confirmation_url_regex,
                                            1,
                                            $url_without_protocol);
  }

  public static function is_tracking_link($proxyable_url_set, $url_without_protocol) {
    return UBHTTP::is_extract_url_proxyable($proxyable_url_set,
                                            "/^(.+)?\/(clkn|clkg)\/?/",
                                            1,
                                            $url_without_protocol);
  }

  public static function get_url_purpose($proxyable_url_set, $http_method, $url) {
    $host = parse_url($url, PHP_URL_HOST);
    $path = rtrim(parse_url($url, PHP_URL_PATH), '/');
    $url_without_protocol = $host . $path;
    UBLogger::debug_var('get_url_purpose $host', $host);
    UBLogger::debug_var('get_url_purpose $path', $path);
    UBLogger::debug_var('get_url_purpose $url_without_protocol', $url_without_protocol);

    if ($http_method == "POST" &&
        preg_match("/^\/(fsn|fsg|fs)\/?$/", $path)) {

      return "SubmitLead";

    } elseif ($http_method == "GET" &&
              UBHTTP::is_tracking_link($proxyable_url_set, $url_without_protocol)) {

      return "TrackClick";

    } elseif ($http_method == "GET" &&
               (in_array($url_without_protocol, $proxyable_url_set) ||
                UBHTTP::is_confirmation_dialog($proxyable_url_set, $url_without_protocol))) {

      return "ViewLandingPage";

    } else {
      return null;
    }
  }

}

?>
