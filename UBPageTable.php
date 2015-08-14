<?php

if( ! class_exists( 'WP_List_Table' ) ) {
  require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class UBPageTable extends WP_List_Table {

  private $item_scroll_threshold = 10;

  function __construct($page_urls) {
    parent::__construct();

    $this->items = array_map(function($url) {
      return array('url' => $url);
    }, $page_urls);

    $this->_column_headers = array(array('url' => 'Url'), array(), array());
  }

  protected function column_default($item, $column_name) {
    switch($column_name) {
    case 'url':
      return "<a href=\"//${item[$column_name]}\" target=\"_blank\">${item[$column_name]}</a>";
      break;
    default:
      return $item[$column_name];
    }
  }

  protected function display_tablenav($which) {
  }

  protected function get_table_classes() {
    $super = parent::get_table_classes();

    if(count($this->items) > $this->item_scroll_threshold) {
      $super[] = 'ub-table-scroll';
    }

    return $super;
  }

}

?>