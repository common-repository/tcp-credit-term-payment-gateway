<?php
defined('ABSPATH') or exit;

class TCP_wc_credit_term_my_account {

  function __construct() {
    add_filter('query_vars', [$this, 'query_vars']);

    add_action('parse_request', [$this, 'parse_request']);
    add_action('woocommerce_before_calculate_totals', [$this, 'calculate_owed_amount'], 20, 1);
    add_action('woocommerce_order_status_completed', [$this, 'order_completed']);
    add_action('woocommerce_order_status_processing', [$this, 'order_processing']);
    add_action('woocommerce_order_status_on-hold', [$this, 'order_on_hold']);
    add_action('woocommerce_account_dashboard', [$this, 'my_credit_info']);
  }

  function query_vars($vars) {
    $vars[] = 'wc_credit_pay_owed';
    return $vars;
  }

  // when click on 'Pay now', go to cart page
  function parse_request($wp) {
    global $woocommerce;
    if (array_key_exists('wc_credit_pay_owed', $wp->query_vars)) {
      $product_id = get_option('wc_credit_pay_owed_product_id');
      if (empty($product_id)) {
        return;
      }
      $woocommerce->cart->empty_cart();
      $woocommerce->cart->add_to_cart($product_id);
      $cart_url = $woocommerce->cart->get_cart_url();
      wp_redirect($cart_url);
      exit;
    }
  }

  // if paying owed amount, set price to owed amount
  function calculate_owed_amount($wc_cart) {
    $product_id = get_option('wc_credit_pay_owed_product_id');
    if (empty($product_id)) {
      return;
    }
    
    $user_id = get_current_user_id();
    $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
    $total_used = $used + $used_over;

    foreach ($wc_cart->get_cart() as $cart_item) {
      $product = $cart_item['data'];
      if ($product->get_id() == $product_id) {
        $product->set_price($total_used);
        break;
      }
    }
  }

  // if paid owed amount, return back credit
  function order_completed($order_id) {
    global $tcp_wc_credit_term;
    $product_id = get_option('wc_credit_pay_owed_product_id');
    if (empty($product_id)) {
      return;
    }
    $order = wc_get_order($order_id);
    if ($order->get_payment_method() == 'credit_gateway') {
      return;
    }
    $user_id = $order->get_customer_id();
    foreach ($order->get_items() as $item) {
      if ($item instanceof WC_Order_Item_Product && $item->get_product_id() == $product_id) {
        $amount = $item->get_total();
        $tcp_wc_credit_term->return_back_credit($user_id, $amount);
        update_user_meta($user_id, 'wc_credit_last_purchase', 0);
        delete_user_meta($user_id, 'wc_credit_paid_owed');
        break;
      }
    }
  }

  // if paying owed amount, set user meta for display in my account
  function order_processing($order_id) {
    $product_id = get_option('wc_credit_pay_owed_product_id');
    if (empty($product_id)) {
      return;
    }
    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() == 'credit_gateway') {
      return;
    }
    $user_id = $order->get_customer_id();
    foreach ($order->get_items() as $item) {
      if ($item instanceof WC_Order_Item_Product && $item->get_product_id() == $product_id) {
        $amount = $item->get_total();
        update_user_meta($user_id, 'wc_credit_paid_owed', [
          'order_id' => $order_id,
          'amount' => $amount,
        ]);
        break;
      }
    }
  }

  function order_on_hold($order_id) {
    $this->order_processing($order_id);
  }

  // display balance credit in 'my account' page.
  function my_credit_info() {
    global $tcp_wc_credit_term;
    if (!is_user_logged_in()) {
      return;
    }
    $user_id = get_current_user_id();
    if (!$tcp_wc_credit_term->user_enabled($user_id)) {
      return;
    }
    $now = $tcp_wc_credit_term->create_datetime();
    $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
    $limit = (float) get_user_meta($user_id, 'wc_credit_limit', true);
    $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
    $total_used = $used + $used_over;
    $balance = $limit - $total_used;
    $paid_owed = (array) get_user_meta($user_id, 'wc_credit_paid_owed', true);
    $total_paid = 0;
    if (!empty($paid_owed) && isset($paid_owed['order_id'], $paid_owed['amount'])) {
      $total_paid = $paid_owed['amount'];
    }
    ?>
    <style>
      #my_credit {
        margin-bottom: 0;
      }
      #my_credit td {
        font-size: 100%;
        border: 0;
        padding-left: 4px;
        padding-right: 4px;
        word-wrap: break-word;
      }
    </style>
    <p>
      <table id="my_credit" cellspacing="0" cellpadding="0">
        <tr>
          <td width="25%">
            <?php _e('Credit Limit', 'wc-term-credit'); ?>:<br><strong><?php echo strip_tags(wc_price($limit)); ?></strong>
          </td>
          <td width="25%">
            <?php _e('Credit Balance', 'wc-term-credit'); ?>:<br><strong><?php echo strip_tags(wc_price($balance)); ?></strong>
          </td>
          <td width="25%">
            <?php _e('Currently Owing', 'wc-term-credit'); ?>:<br><strong><?php echo strip_tags(wc_price($total_used)); ?></strong>
            <?php if ($total_used > 0 && $total_paid == 0) { ?>
              (<a href="<?php echo esc_url(home_url('?wc_credit_pay_owed=1')); ?>"><?php _e('Pay now', 'wc-term-credit'); ?></a>)
            <?php } ?>
          </td>
          <td>
            <?php _e('Due date', 'wc-term-credit'); ?>:<br>
          <?php if (!empty($due)) {
            $d = $tcp_wc_credit_term->create_datetime($due); ?>
            <strong><?php echo $d->format('j M Y, H:i'); ?></strong>
          <?php } else { echo '-'; } ?>
          </td>
        </tr>
      </table>
    </p><?php
  }

}

new TCP_wc_credit_term_my_account();
