<?php

require_once dirname(__FILE__) . '/UBConfig.php';

class UBLogger {

  // should be called when the plugin is loaded
  public static function setup_logger() {
    if(!isset($GLOBALS['wp_log_plugins'])) {
      $GLOBALS['wp_log_plugins'] = array();
    }
    $GLOBALS['wp_log_plugins'][UBConfig::UB_PLUGIN_NAME] = array();
    $GLOBALS['wp_log_plugins'][UBConfig::UB_PLUGIN_NAME . '-vars'] = array();
  }

  public static function upload_logs_to_unbounce($url) {
    if(UBConfig::remote_debug_logging_enabled()) {
      $datetime = new DateTime('NOW', new DateTimeZone('UTC'));
      $data = array(
        'type' => 'WordpressLogV1.0',
        'messages' => $GLOBALS['wp_log'][UBConfig::UB_PLUGIN_NAME],
        'vars' => $GLOBALS['wp_log'][UBConfig::UB_PLUGIN_NAME . '-vars'],
        'id' => uniqid(),
        'time_sent' => $datetime->format('Y-m-d\TH:i:s.000\Z'),
        'source' => UBConfig::UB_USER_AGENT . ' ' . gethostname()
      );
      $json_unescaped = json_encode($data);
      $data_string = str_replace('\\/', '/', $json_unescaped);

      $curl = curl_init();
      $curl_options = array(
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_USERAGENT => UBConfig::UB_USER_AGENT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array(
          'Content-Type: application/json',
          'Content-Length: ' . strlen($data_string)
        ),
        CURLOPT_POSTFIELDS => $data_string,
        CURLOPT_TIMEOUT => 2
      );
      curl_setopt_array($curl, $curl_options);
      $success = curl_exec($curl);
      $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

      if(!$success) {
        $message = 'Unable to send log messages to ' . $url . ': "'
                 . curl_error($curl) . '" - HTTP status: ' . curl_errno($curl);
        UBLogger::warning($message);
      } elseif($http_code >= 200 && $http_code < 300) {
        $message = 'Successfully sent log messsages to ' . $url
                 . ' - HTTP status: ' . $http_code;
        UBLogger::debug($message);
      } else {
        $message = 'Unable to send log messages to ' . $url
                 . ' - HTTP status: ' . $http_code;
        UBLogger::warning($message);
      }

      curl_close($curl);
    }
  }

  public static function format_log_entry($level, $msg) {
    $msg = is_string($msg) ? $msg : print_r($msg, true);
    return '[' . UBConfig::UB_PLUGIN_NAME . '] [' . $level . '] ' . $msg;
  }

  private static function log_wp_log($log_entry) {
    $GLOBALS['wp_log'][UBConfig::UB_PLUGIN_NAME][] = $log_entry;
  }

  private static function log_wp_log_var($var, $val) {
    $GLOBALS['wp_log'][UBConfig::UB_PLUGIN_NAME . '-vars'][$var] = $val;
  }

  private static function log_error_log($log_entry) {
    error_log($log_entry);
  }

  public static function log($level, $msg) {
    if(UBConfig::debug_loggging_enabled()) {
      $log_entry = UBLogger::format_log_entry($level, $msg);
      UBLogger::log_wp_log($log_entry);
      UBLogger::log_error_log($log_entry);
    }
  }

  public static function log_var($level, $var, $val) {
    if(UBConfig::debug_loggging_enabled()) {
      UBLogger::log($level, '$' . $var . ': ' . $val);
      UBLogger::log_wp_log_var($var, $val);
    }
  }

  public static function info($msg) {
    UBLogger::log('INFO', $msg);
  }

  public static function warning($msg) {
    UBLogger::log('WARNING', $msg);
  }

  public static function debug($msg) {
    UBLogger::log('DEBUG', $msg);
  }

  public static function debug_var($var, $val) {
    UBLogger::log_var('DEBUG', $var, $val);
  }

  public static function config($msg) {
    UBLogger::log('CONFIG', $msg);
  }

}

?>
