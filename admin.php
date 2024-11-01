<?php
defined('ABSPATH') or exit;

class TCP_wc_credit_term_admin {

  function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'admin_init']);
    add_action('admin_notices', [$this, 'admin_notices']);
  }

  //---------------------------------------------------------------------------
  // hooks
  //---------------------------------------------------------------------------

  // admin menu init
  function admin_menu() {
    global $tcp_wc_credit_term;
    add_submenu_page( 
      'thecartpress', // string $parent_slug, 
      __('TCP Credit Term Payment Gateway', 'wc-term-credit'), // string $page_title, 
      __('Credit Term Payment Gateway', 'wc-term-credit'), // string $menu_title, 
      'manage_options', // string $capability, 
      'wc_credit_term_admin', // string $menu_slug, 
      [$this, 'create_admin_page'] // callable $function = '', 
    );
    if ($tcp_wc_credit_term->premium_active() && !wp_next_scheduled('wc_credit_daily_cron')) {
      $d = new DateTime();
      $tz = timezone_open($tcp_wc_credit_term->get_timezone());
      if (!empty($tz)) {
        $d->setTimezone($tz);
      }
      $d->modify('tomorrow');
      wp_schedule_event($d->getTimestamp(), 'daily', 'wc_credit_daily_cron');
    }
  }

  // setup for settings page
  function admin_init() {
    global $tcp_wc_credit_term;
    register_setting(
      'wc_credit', // option_group
      'wc_credit_allow_over_limit', // option_name
      [
        'type' => 'boolean',
        'sanitize_callback' => [$this, 'sanitize_allow_over_limit'],
        'default' => false,
      ]
    );
    register_setting(
      'wc_credit', // option_group
      'wc_credit_order_status', // option_name
      [
        'type' => 'string',
        'sanitize_callback' => [$this, 'sanitize_order_status'],
        'default' => 'processing',
      ]
    );
    register_setting(
      'wc_credit', // option_group
      'wc_credit_default_limit', // option_name
      [
        'type' => 'number',
        'sanitize_callback' => [$this, 'sanitize_default_limit'],
        'default' => TCP_wc_credit_term::CREDIT_LIMIT,
      ]
    );
    $terms = $tcp_wc_credit_term->get_credit_term_options();
    register_setting(
      'wc_credit', // option_group
      'wc_credit_default_term', // option_name
      [
        'type' => 'string',
        'sanitize_callback' => [$this, 'sanitize_default_term'],
        'default' => $terms[0],
      ]
    );
    
    add_settings_section( 
      'wc_credit_section', // id
      '', // title
      [$this, 'section_info'], // callback
      'wc_credit_term_admin' // page slug
    );

    add_settings_field(
      'wc_credit_order_status', // id
      __('Order status', 'wc-term-credit'), // title
      [$this, 'order_status_field'], // callback
      'wc_credit_term_admin', // page
      'wc_credit_section' // section
    );
    add_settings_field(
      'wc_credit_allow_over_limit', // id
      __('Spend over credit limit', 'wc-term-credit'), // title
      [$this, 'allow_over_limit_field'], // callback
      'wc_credit_term_admin', // page
      'wc_credit_section' // section
    );
    add_settings_field(
      'wc_credit_default_limit',
      __('Default credit limit', 'wc-term-credit'),
      [$this, 'default_limit_field'],
      'wc_credit_term_admin',
      'wc_credit_section'
    );
    add_settings_field(
      'wc_credit_default_term',
      __('Default credit term', 'wc-term-credit'),
      [$this, 'default_term_field'],
      'wc_credit_term_admin',
      'wc_credit_section'
    );

    do_action('wc_credit_admin_init');
  }

  // show success notice in user edit profile page after reset credit/due
  function admin_notices() {
    $message = '';
    if (get_transient('wc_credit_credit_reset')) {
      delete_transient('wc_credit_credit_reset');
      $message = __('Credit has been reset', 'wc-term-credit');
    } else if (get_transient('wc_credit_due_reset')) {
      delete_transient('wc_credit_due_reset');
      $message = __('Due date has been reset', 'wc-term-credit');
    }
    if (!empty($message)) {
      ?>
      <div class="notice notice-success is-dismissible"> 
        <p><?php echo esc_attr($message); ?></p>
        <button type="button" class="notice-dismiss">
          <span class="screen-reader-text"><?php _e('Dismiss this notice.', 'wc-term-credit'); ?></span>
        </button>
      </div>
      <?php
    } else {
      $notice = get_transient('wc_credit_notice');
      if (is_array($notice) && isset($notice['status'], $notice['message'])) { ?>
        <div class="notice notice-<?php echo $notice['status']; ?> is-dismissible">
          <p><?php echo $notice['message']; ?></p>
          <button type="button" class="notice-dismiss">
            <span class="screen-reader-text"><?php _e('Dismiss this notice', 'wc-term-credit'); ?></span>
          </button>
        </div>
        <?php
        delete_transient('wc_credit_notice');
      }
    }
  }

  //---------------------------------------------------------------------------
  // functions
  //---------------------------------------------------------------------------

  // create admin page
  function create_admin_page() {
    global $tcp_wc_credit_term;
    $page_url = admin_url('admin.php?page=wc_credit_term_admin');
    $current_url = $page_url;
    $tab = 'settings';
    if (isset($_GET['tab']) && $_GET['tab'] == 'premium') {
      $tab = sanitize_text_field($_GET['tab']);
      $current_url = add_query_arg('tab', $tab, $current_url);
    }
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php _e('TCP Credit Term Payment Gateway', 'wc-term-credit'); ?></h1>
      <hr class="wp-header-end">
      <?php if ($tcp_wc_credit_term->premium_installed()) { ?>
        <ul class="subsubsub">
          <li>
            <a href="<?php echo esc_url($page_url); ?>"<?php echo $tab == 'settings' ? ' class="current"' : ''; ?>><?php _e('Settings', 'wcjsonsync'); ?></a> |
          </li>
          <li>
            <a href="<?php echo esc_url(add_query_arg('tab', 'premium', $page_url)); ?>"<?php echo $tab == 'premium' ? ' class="current"' : ''; ?>><?php _e('Premium', 'wcjsonsync'); ?></a>
          </li>
        </ul>
      <?php } ?>
      <div class="clear"></div>
      <?php if ($tab == 'settings') { ?>
        
        <h2><?php _e('Settings', 'wcjsonsync'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
          <?php
          settings_fields('wc_credit');
          do_settings_sections('wc_credit_term_admin');
          submit_button();
          ?>
        </form>

        <?php do_action('wc_credit_admin_page'); ?>
        
      <?php } else if ($tab == 'premium') { ?>

        <!-- premium settings -->
        <?php do_action('wc_credit_premium_setting'); ?>

      <?php } ?>

    </div>
    <?php
  }

  function sanitize_order_status($input) {
    global $tcp_wc_credit_term;
    $status = sanitize_text_field($_POST['wc_credit_order_status']);
    $statuses = $tcp_wc_credit_term->get_order_statuses();
    if (in_array($status, array_keys($statuses))) {
      return $status;
    }
    return $input;
  }

  function sanitize_allow_over_limit($input) {
    $v = (int) $_POST['wc_credit_allow_over_limit'];
    return $v == 1;
  }

  function sanitize_default_limit($input) {
    return (int) $_POST['wc_credit_default_limit'];
  }

  function sanitize_default_term($input) {
    global $tcp_wc_credit_term;
    $terms = $tcp_wc_credit_term->get_credit_term_options();
    $v = sanitize_text_field($_POST['wc_credit_default_term']);
    if (!in_array($v, $terms)) {
      $v = 0;
    }
    return $v;
  }

  function section_info() {}

  function order_status_field() {
    global $tcp_wc_credit_term;
    $selected = get_option('wc_credit_order_status');
    echo '<select name="wc_credit_order_status">';
    $statuses = $tcp_wc_credit_term->get_order_statuses();
    foreach ($statuses as $k => $v) {
      echo '<option value="'. $k .'"';
      echo $k == $selected ? ' selected': ''; 
      echo '>'. $v .'</option>';
    }
    echo '</select>';
    echo '<br><span class="description">'. __('Status after order placed', 'wc-term-credit') .'</span>';
  }

  function allow_over_limit_field() {
    $allowed = get_option('wc_credit_allow_over_limit', false);
    echo '<label><input type="checkbox" name="wc_credit_allow_over_limit" value="1"'. ($allowed ? ' checked' : '') .'> '. __('Enabled', 'wc-term-credit') .'</label>';
    echo '<br><span class="description">'. __('Allow users to spend more after reaching their credit limit', 'wc-term-credit') .'</span>';
  }

  function default_limit_field() {
    $value = get_option('wc_credit_default_limit', TCP_wc_credit_term::CREDIT_LIMIT);
    echo '<input type="number" name="wc_credit_default_limit" value="'. esc_attr($value) .'" class="regular-text">';
    echo '<br><span class="description">'. __('Default customers\' credit limit when plugin activated or when new customer registered.', 'wc-term-credit') .'</span>';
  }

  function default_term_field() {
    global $tcp_wc_credit_term;
    $selected = get_option('wc_credit_default_term', 0);
    $terms = $tcp_wc_credit_term->get_credit_term_options();
    echo '<select name="wc_credit_default_term">';
    foreach ($terms as $v) {
      echo '<option value="'. esc_attr($v) .'"';
      if ($selected == $v) {
        echo ' selected';
      }
      echo '>';
      if ($v == 'bb') {
        echo __('Bill-to-bill', 'wc-term-credit');
      } else if ($v == 0) {
        echo __('Disabled', 'wc-term-credit');
      } else {
        echo sprintf(__('%d days', 'wc-term-credit'), $v);
      }
      echo '</option>';
    }
    echo '</select>';
    echo '<br><span class="description">'. __('Default customers\' credit term when plugin activated or when new customer registered.', 'wc-term-credit') .'</span>';
  }

}

new TCP_wc_credit_term_admin();
