<?php
defined('ABSPATH') or exit;

class TCP_Payment_Gateway_Credit extends WC_Payment_Gateway {
  
  function __construct() {
    $this->id = 'credit_gateway';
    $this->icon = apply_filters('woocommerce_credit_icon', '');
    $this->has_fields = false;
    $this->method_title = __('Credit', 'wc-term-credit');
    $this->method_description = __('Pay using credit', 'wc-term-credit');

    // load settings
    $this->init_form_fields();
    $this->init_settings();

    // define user set variables
    $this->title = $this->get_option('title');
    
    // actions
    add_action('woocommerce_update_options_payment_gateways_'. $this->id, [$this, 'process_admin_options']);
    add_action('woocommerce_thankyou_'. $this->id, [$this, 'thankyou_page']);

    // customer emails
    add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
  }

  function init_description($user_id = null) {
    global $tcp_wc_credit_term;
  	if (empty($user_id)) {
  		$user_id = get_current_user_id();
  	}
    $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
    $limit = (float) get_user_meta($user_id, 'wc_credit_limit', true);
    $balance = $limit - ($used + $used_over);

    $credit_over = false;
    $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
    $term = get_user_meta($user_id, 'wc_credit_term', true);
    $term_day = (int) $term;
    if ($term_day > 0) {
      $tcp_wc_credit_term->get_new_due_date($user_id, $due, $term_day, $credit_over);
    }
    if (($used + $used_over) >= $limit) {
      $credit_over = true;
    }
    
    $this->description = sprintf(__('Pay using credit, your balance: %s', 'wc-term-credit'), wc_price($balance));
    if ($credit_over) {
      $this->description = $this->description . '<br><span class="credit-warning" style="color: red">'. __('Warning: Your credit has been overdue or over limit.', 'wc-term-credit') .'</span>';
    }
    $this->instructions = $this->description;
  }

  // initialize gateway settings form fields
  function init_form_fields() {
    $this->init_description();
    $this->form_fields = apply_filters('wc_credit_form_fields', [

      'enabled' => [
        'title' => __('Enable/Disable', 'wc-term-credit'),
        'type' => 'checkbox',
        'label' => __('Enable payment on credit', 'wc-term-credit'),
        'default' => 'yes',
      ],

      'title' => [
        'title' => __('Title', 'wc-term-credit'),
        'type' => 'text',
        'description' => __('This controls the title for the payment method the customer sees during checkout', 'wc-term-credit'),
        'default' => __('Payment on credit', 'wc-term-credit'),
        'desc_tip' => true,
      ],

      'description' => [
        'title' => __('Description', 'wc-term-credit'),
        'type' => 'textarea',
        'description' => __('Payment method description that the customer will see on your checkout', 'wc-term-credit'),
        'default' => $this->description,
        'desc_tip' => true,
      ],

      'instructions' => [
        'title' => __('Instructions', 'wc-term-credit'),
        'type' => 'textarea',
        'description' => __('Instructions that will be added to the thank you page and emails', 'wc-term-credit'),
        'default' => '',
        'desc_tip' => true,
      ],

    ]);
  }

  // output for the order received page
  function thankyou_page() {
    if ($this->instructions) {
      echo wpautop(wptexturize($this->instructions));
    }
  }

  // add content to the wc emails
  function email_instructions($order, $sent_to_admin, $plain_text = false) {
    if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('on-hold')) {
      echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
    }
  }

  // process payment & return the result
  function process_payment($order_id) {
    global $tcp_wc_credit_term, $wpdb;
    
    $order = wc_get_order($order_id);
    $user_id = $order->get_customer_id();
    $status = get_option('wc_credit_order_status');
    $credit_over = false;

    // set due date
    $due = (int) get_user_meta($user_id, 'wc_credit_due', true);
    $term = get_user_meta($user_id, 'wc_credit_term', true);
    $term_day = (int) $term;
    $now = $tcp_wc_credit_term->create_datetime();
    
    if ($term == 'bb') {
      update_user_meta($user_id, 'wc_credit_due', $now->getTimestamp());
    } else if ($term_day > 0) {
      $new_due_date = $tcp_wc_credit_term->get_new_due_date($user_id, $due, $term_day, $credit_over);
      if (!is_null($new_due_date)) {
        update_user_meta($user_id, 'wc_credit_due', $new_due_date->getTimestamp());
      }
    }

    // accumulate used credit
    $used = (float) get_user_meta($user_id, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user_id, 'wc_credit_used_over', true);
    $limit = (float) get_user_meta($user_id, 'wc_credit_limit', true);
    $order_total = $order->get_total();
    
    if (($used + $used_over) >= $limit) {
      $credit_over = true;
    } else {
      $used += $order_total;
      update_user_meta($user_id, 'wc_credit_used', $used);
    }
    
    // used credit over limit or over due
    if ($credit_over) {
      $status = 'pending-payment';
      $used_over += $order_total;
      if ($used < $limit) {
        $amount = $limit - $used;
        update_user_meta($user_id, 'wc_credit_used', $limit);
        $used_over -= $amount;
      }
      update_user_meta($user_id, 'wc_credit_used_over', $used_over);
    }

    // update status (completed, processing, pending-payment, on-hold, refunded, cancelled, failed)
    if (empty($status)) {
      $status = 'processing';
    }
    $statuses = $tcp_wc_credit_term->get_order_statuses();
    $order->update_status($status, $statuses[$status]);
    update_user_meta($user_id, 'wc_credit_last_purchase', $now->getTimestamp());

    // update table {prefix}wc_credit_order
    $table_name = $wpdb->prefix . TCP_wc_credit_term::DB_TABLE;
    $now->modify('+'. $term_day .' days');
    $wpdb->insert($table_name, [
      'user_id' => $user_id,
      'order_id' => $order_id,
      'due_date' => $now->getTimestamp(),
      'amount_owing' => $order_total,
      'amount_paid' => 0.0
    ]);
    
    // reduce stock levels
    $order->reduce_order_stock();

    // remove cart
    WC()->cart->empty_cart();

    $this->init_description($user_id);

    // return thankyou redirect
    return [
      'result' => 'success',
      'redirect' => $this->get_return_url($order),
    ];
  }
}