<?php

class UBUtil {

  public static function array_select_by_key($input, $keep) {
    return array_intersect_key($input, array_flip($keep));
  }

  public static function array_fetch($array, $index, $default = null) {
    return isset($array[$index]) ? $array[$index] : $default;
  }

}
?>