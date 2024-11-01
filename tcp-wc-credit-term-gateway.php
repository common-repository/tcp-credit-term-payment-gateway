<?php
/*
  Plugin Name: TCP Credit Term Payment Gateway
  Plugin URI: 
  Description: Add option to pay using term credit in your WooCommerce store.
  Version: 1.0.0
  WC tested up to: 5.2.0
  Author: TCP Team
  Author URI: https://www.thecartpress.com
 */

defined('ABSPATH') or exit;

class TCP_wc_credit_term {

  const ENABLE_DEBUG = false;
  // const DEBUG_REFERER = 'http://4732be1f4df3.ngrok.io/wordpress';
  
  const PLUGIN_ID = 'tcp-wc-credit-term-gateway';
  const CREDIT_LIMIT = 5000.0;
  const CREDIT_TERMS = '0,30,60,90';
  const REMINDER_DAYS = 15;
  const DB_TABLE = 'wc_credit_orders';

  var $_premium_installed;
  var $_premium_active;
  var $_user_enabled = [];

  function __construct() {
  
    // check woocommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
      return;
    }

    register_activation_hook(__FILE__, [$this, 'activation']);

    add_filter('plugin_action_links_'. plugin_basename(__FILE__), [$this, 'plugin_action_links']);
    add_filter('woocommerce_payment_gateways', [$this, 'payment_gateways']);
    add_filter('woocommerce_available_payment_gateways', [$this, 'available_payment_gateways']);
    add_filter('process_woo_wallet_general_cashback', [$this, 'process_general_cashback'], 20, 2);

    add_action('plugins_loaded', [$this, 'plugins_loaded'], 11);
    add_action('user_register', [$this, 'user_register']);
    add_action('woocommerce_order_status_cancelled', [$this, 'order_status_cancelled']);
    add_action('woocommerce_order_refunded', [$this, 'order_refunded'], 20, 2);
    add_action('woo_wallet_form_cart_cashback_amount', [$this, 'cart_cashback_amount'], 20);

    require_once __DIR__ . '/tcp-menu.php';
    require_once __DIR__ . '/admin.php';
    require_once __DIR__ . '/profile.php';
    require_once __DIR__ . '/my_account.php';
  }

  // --------------------------------------------------------------------------
  // filters & actions 
  // --------------------------------------------------------------------------

  // on plugin activated, add credit_balance to all user with role = client
  function activation() {

    // populate
    $users = get_users([
      'fields' => ['ID'],
      'role__in' => $this->get_roles(),
    ]);
    foreach ($users as $user) {
      $limit = get_user_meta($user->ID, 'wc_credit_limit', true);
      if (!empty($limit)) {
        continue;
      }
      $this->populate_data($user->ID);
    }

    // create custom db table
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . self::DB_TABLE;
    $sql = "CREATE TABLE `$table_name` (
      id bigint(20) NOT NULL AUTO_INCREMENT,
      user_id bigint(20) NOT NULL,
      order_id bigint(20) NOT NULL,
      due_date int(11) NOT NULL,
      amount_owing float(9,2) NOT NULL,
      amount_paid float(9,2) NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // create hidden product for customer to pay their owed credits
    $product_id = (int) get_option('wc_credit_pay_owed_product_id');
    if (empty($product_id)) {
      $p = new WC_Product_Simple();
      $p->set_name(__('Credit owed', 'wc-term-credit'));
      $p->set_status('publish');
      $p->set_catalog_visibility('hidden');
      $p->set_regular_price('1.00');
      $p->set_manage_stock(false);
      $p->set_stock_status('instock');
      $p->set_reviews_allowed(false);
      $p->set_virtual(true);
      $p->set_downloadable(false);
      $product_id = $p->save();
      update_option('wc_credit_pay_owed_product_id', $product_id, false);
    }
  }

  // add payment method link in woocommerce settings page
  function plugin_action_links($links) {
    $plugin_links = [
      '<a href="'. admin_url('admin.php?page=wc_credit_term_admin') .'">'. __('Settings', 'wc-term-credit') .'</a>'
    ];
    return array_merge($plugin_links, $links);
  }

  // add gateway to the list
  function payment_gateways($gateways) {
    $gateways[] = 'TCP_Payment_Gateway_Credit';
    return $gateways;
  }

  // disable payment gateway = insufficient credit / due date
  function available_payment_gateways($gateways) {
    global $woocommerce;

    if (isset($gateways['credit_gateway'])) {
  
      $disable = false;
      $user_id = get_current_user_id();

      // disable gateway if did purchase (due date is set) & due date setting is bill-to-bill
      if ($this->user_enabled()) {
        $term = get_user_meta($user_id, 'wc_credit_term', true);
        $bill2bill = $term == 'bb';
        if ($bill2bill) {
          $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
          $disable = !empty($due);
        } else if ($term == 0) {
          $disable = true;
        }
      } else {
        $disable = true;
      }

      // disable gateway if paying for owed credit
      $product_id = get_option('wc_credit_pay_owed_product_id');
      if (!$disable && !empty($product_id) && $woocommerce->cart) {
        foreach ($woocommerce->cart->get_cart() as $cart_item) {
          $product = $cart_item['data'];
          if ($product->get_id() == $product_id) {
            $disable = true;
            break;
          }
        }
      }

      // allow spend over limit
      $allowed = (bool) get_option('wc_credit_allow_over_limit', false);
      if (!$disable) {
        $credit_over = false;
        $limit = (float) get_user_meta($user_id, 'wc_credit_limit', true);
        $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
        $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
        if ($woocommerce->cart) {
          $cart_total = $woocommerce->cart->get_total('');
          $credit_over = !$allowed && ($cart_total > ($limit - ($used + $used_over))); // cart total exceed credit balance
        }
        if (!$credit_over) {
          $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
          $term = get_user_meta($user_id, 'wc_credit_term', true);
          $term_day = (int) $term;
          if ($term_day > 0) {
            $this->get_new_due_date($user_id, $due, $term_day, $credit_over);
          }
          if (!$allowed && ($used + $used_over) >= $limit) {
            $credit_over = true;
          }
        }
        if ($credit_over) {
          $disable = true;
        }
      }

      if ($disable) {
        unset($gateways['credit_gateway']);
      }

    }
    return $gateways;
  }

  function process_general_cashback($has_txn_id, $order) {
    $product_id = get_option('wc_credit_pay_owed_product_id');
    if (!empty($product_id)) {
      $items = $order->get_items();
      foreach ($items as $item) {
        if ($item instanceof WC_Order_Item_Product && $item->get_product_id() == $product_id) {
          return false;
        }
      }
    }
    return $has_txn_id;
  }

  // credit payment gateway class
  function plugins_loaded() {
    require_once __DIR__ . '/gateway.php';
  }

  // on register new user, if role = client, add credit_balance
  function user_register($user_id) {
    if ($this->user_enabled($user_id, true)) {
      $this->populate_data($user_id);
    }
  }

  function order_status_cancelled($order_id) {
    $this->order_refunded($order_id, 0);
  }

  // give back credit to customer,
  // if over due / spent = wc_credit_used_over
  // else = wc_credit_used
  function order_refunded($order_id, $refund_id) {
    global $wpdb;

    $order = wc_get_order($order_id);
    $user_id = $order->get_customer_id();

    if ($order->get_payment_method() != 'credit_gateway') {
      
      // cancel / refund order to pay owed amount
      $paid_owed = (array) get_user_meta($user_id, 'wc_credit_paid_owed', true);
      if (isset($paid_owed['order_id']) && $paid_owed['order_id'] == $order_id) {
        delete_user_meta($user_id, 'wc_credit_paid_owed');
      }
      return;
    }

    // delete order from {prefix}wc_credit_orders
    $table_name = $wpdb->prefix . self::DB_TABLE;
    $result = $wpdb->delete($table_name, [
      'user_id' => $user_id,
      'order_id' => $order_id
    ], ['%d', '%d']);
    if (empty($result)) {
      return;
    }

    // get refund amount
    $amount = 0;
    if (!empty($refund_id)) {
      $order_refunds = $order->get_refunds();
      foreach ($order_refunds as $refund) {
        if ($refund->get_id() == $refund_id) {
          $amount = $refund->get_amount();
          break;
        }
      }
    }
    if (empty($amount)) {
      $amount = $order->get_total();
    }

    $this->return_back_credit($user_id, $amount, true);
  }

  function cart_cashback_amount($cashback_amount) {
    $product_id = get_option('wc_credit_pay_owed_product_id');
    if (!empty($product_id)) {
      foreach (wc()->cart->get_cart() as $cart_item) {
        $product = $cart_item['data'];
        if ($product->get_id() == $product_id) {
          return 0.0;
        }
      }
    }
    return $cashback_amount;
  }

  // --------------------------------------------------------------------------
  // functions
  // --------------------------------------------------------------------------

  function get_order_statuses() {
    return [
      'completed' => __('Completed', 'wc-term-credit'),
      'processing' => __('Processing', 'wc-term-credit'),
      'pending' => __('Pending payment', 'wc-term-credit'),
      'on-hold' => __('On hold', 'wc-term-credit'),
      'refunded' => __('Refunded', 'wc-term-credit'),
      'cancelled' => __('Cancelled', 'wc-term-credit'),
      'failed' => __('Failed', 'wc-term-credit'),
    ];
  }

  function premium_installed() {
    if (is_null($this->_premium_installed)) {
      $this->_premium_installed = in_array('tcp-wc-credit-term-gateway-premium/tcp-wc-credit-term-gateway-premium.php', apply_filters('active_plugins', get_option('active_plugins')));
    }
    return $this->_premium_installed;
  }

  function premium_active() {
    if (is_null($this->_premium_active)) {
      $premium_info = get_option('wc_credit_premium_info', []);
      $this->_premium_active = false;
      if ($this->premium_installed() && !empty($premium_info) && isset($premium_info['premium']) && $premium_info['premium']) {
        if (isset($premium_info['expiry'])) {
          $now = $this->create_datetime();
          $expiry = $this->create_datetime($premium_info['expiry']);
          if (!empty($expiry) && $now <= $expiry) {
            $this->_premium_active = true;
          }
        } else {
          $this->_premium_active = true; // premium without expiry
        }
      }
    }
    return $this->_premium_active;
  }

  function get_credit_term_options() {
    $terms = $this->premium_active() ? TCP_wc_credit_term_premium::CREDIT_TERMS : self::CREDIT_TERMS;
    return explode(',', $terms);
  }

  function get_roles() {
    return ['customer', 'wholesale_customer'];
  }

  // only wholesale_customer role will have payment by term credit
  function user_enabled($user_id = null, $new_user = false) {
    if (empty($user_id)) {
      $user_id = get_current_user_id();
    }
    if (isset($this->_user_enabled[$user_id])) {
      return $this->_user_enabled[$user_id];
    }
    $meta = get_userdata($user_id);
    $user_roles = $meta->roles;
    if (!is_array($user_roles)) {
      $user_roles = [];
    }
    $wholesale_roles_ids = $this->get_roles();
    $roles = array_intersect($user_roles, $wholesale_roles_ids);
    $enabled = !empty($roles);
    if (!$new_user) {
      $term = get_user_meta($user_id, 'wc_credit_term', true);
      if ($term != 'bb' && $term == 0) {
        $enabled = false;
      }
    }
    $this->_user_enabled[$user_id] = $enabled;
    return $enabled;
  }

  // int $user_id, int $due, int $term_day, bool &$credit_over
  function get_new_due_date($user_id, $due, $term_day, &$credit_over) {
    $new_due_date = null;
    $now = $this->create_datetime();

    // initial purchase will set a due date. 
    // 1 credit term = due date + term_day, keep repeating every term_day
    if (empty($due)) {
      $new_due_date = $this->create_datetime($now->getTimestamp());
      $new_due_date->modify('+'. $term_day .' days');
    } else {
      $due_date = $this->create_datetime($due);
      $due_date->setTime(23, 59, 59);
      if ($due_date < $now) {
        $due_date->modify('+'. $term_day .' days');
        if ($due_date < $now) {
          // if due_date already passed more than 1 credit term, 
          // need to reset to next due date
          $b = true;
          do {
            $due_date->modify('+'. $term_day .' days');
            if ($due_date > $now) {
              $b = false;
            }
          } while ($b);
          $new_due_date = $this->create_datetime($due_date->getTimestamp());
        } else {
          $last_purchase = (int) get_user_meta($user_id, 'wc_credit_last_purchase', true);
          if (!empty($last_purchase)) {
            // check if did purchase during previous 1 credit term.
            // note: when user settled payment, must reset (empty) last_purchase
            $last_purchase_date = $this->create_datetime($last_purchase);
            $start = $this->create_datetime($due);
            $start->modify('-'. $term_day .' days');
            $start->setTime(23, 59, 59);
            $end = $this->create_datetime($due);
            $end->setTime(23, 59, 59);
            $in_between = $start < $last_purchase_date && $last_purchase_date < $end;
            if (!$in_between) {
              $new_due_date = $this->create_datetime($due_date->getTimestamp());
            }
          }
        }
        if (is_null($new_due_date)) {
          $credit_over = true;
        }
      }
    }

    return $new_due_date;
  }

  // populate customers' credit metadata when (1) plugin activated, and (2) new user registered
  function populate_data($user_id) {
    $limit = get_option('wc_credit_default_limit', self::CREDIT_LIMIT);
    add_user_meta($user_id, 'wc_credit_used', 0.0); // currency
    add_user_meta($user_id, 'wc_credit_limit', $limit); // currency
    add_user_meta($user_id, 'wc_credit_due', 0); // timestamp, secs
    add_user_meta($user_id, 'wc_credit_term', get_option('wc_credit_default_term', 0)); // days
    add_user_meta($user_id, 'wc_credit_used_over', 0.0); // currency
    add_user_meta($user_id, 'wc_credit_last_purchase', 0); // timestamp, secs
    return $limit;
  }

  /// https://wordpress.stackexchange.com/a/283094
  function get_timezone() {
    // return 'Asia/Kuala_Lumpur';
    $tz = get_option('timezone_string');
    if (!empty($tz)) {
      return $tz;
    }
    $offset = get_option('gmt_offset');
    $hours = (int) $offset;
    $minutes = abs(($offset - (int) $offset) * 60);
    return sprintf('%+03d:%02d', $hours, $minutes);
  }

  function create_datetime($timestamp = 0) {
    $d = new DateTime();
    $tz = $this->get_timezone();
    if (!empty($tz)) {
      $dtz = new DateTimeZone($tz);
      $d->setTimezone($dtz);
    }
    if (!empty($timestamp)) {
      $d->setTimestamp($timestamp);
    }
    return $d;
  }

  function return_back_credit($user_id, $amount, $is_refund = false) {

    // check if credit due or exceed limit
    $credit_over = false;
    $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
    $term = get_user_meta($user_id, 'wc_credit_term', true);
    $term_day = (int) $term;
    
    if ($term_day > 0) {
      $this->get_new_due_date($user_id, $due, $term_day, $credit_over);
    }

    $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
    $limit = (float) get_user_meta($user_id, 'wc_credit_limit', true);
    if (($used + $used_over) > $limit) {
      $credit_over = true;
    }

    // update credit_used meta
    if ($used_over > 0) {
      if ($amount > $used_over) {
        $amount -= $used_over;
        update_user_meta($user_id, 'wc_credit_used_over', 0.0);
      } else {
        $used_over -= $amount;
        update_user_meta($user_id, 'wc_credit_used_over', $used_over);
        $amount = 0;
      }
    }
    if ($used > 0) {
      if ($amount >= $used) {
        update_user_meta($user_id, 'wc_credit_used', 0.0);
        update_user_meta($user_id, 'wc_credit_due', 0);
      } else {
        $used -= $amount;
        update_user_meta($user_id, 'wc_credit_used', $used);
      }
    }

    if ($is_refund) {
      return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . self::DB_TABLE;
    $wpdb->query($wpdb->prepare("
      UPDATE $table_name 
      SET amount_paid = amount_owing
      WHERE user_id = %d
    ", $user_id));
  }

}

$GLOBALS['tcp_wc_credit_term'] = new TCP_wc_credit_term();
