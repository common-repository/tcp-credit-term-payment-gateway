<?php
defined('ABSPATH') or exit;

class TCP_wc_credit_term_profile {

  function __construct() {
    add_action('show_user_profile', [$this, 'user_profile_fields']);
    add_action('edit_user_profile', [$this, 'user_profile_fields']);
    add_action('personal_options_update', [$this, 'user_profile_save']);
    add_action('edit_user_profile_update', [$this, 'user_profile_save']);
    add_action('admin_post_reset_credit', [$this, 'reset_credit']);
    add_action('admin_post_reset_due', [$this, 'reset_due']);
  }

  // add section to show credit_balance in user profile page
  function user_profile_fields($user) {
    global $tcp_wc_credit_term;
    $limit = (float) get_user_meta($user->ID, 'wc_credit_limit', true);
    if (empty($limit)) {
      $limit = $tcp_wc_credit_term->populate_data($user->ID);
    }
    $used = (float) get_user_meta($user->ID, 'wc_credit_used', true);
    $used_over = (float) get_user_meta($user->ID, 'wc_credit_used_over', true);
    $balance = $limit - ($used + $used_over);
    ?>
    <h3><?php _e('User Credit', 'wc-term-credit'); ?></h3>
    <table class="form-table">
      <tr>
        <th><label for="credit_limit"><?php _e('Credit Limit', 'wc-term-credit'); ?></label></th>
        <td>
          <input id="credit_limit" class="regular-text" type="text" name="credit_limit" value="<?php echo esc_attr($limit); ?>">
          <br>
          <span class="description"><?php _e('Max credit you can spend', 'wc-term-credit'); ?></span>
        </td>
      </tr>
      <tr>
        <th><label for="credit_balance"><?php _e('Credit Balance', 'wc-term-credit'); ?></label></th>
        <td>
          <p id="credit_balance">
            <?php echo wc_price($balance); ?> 
            <button type="button" class="button" onclick="tcp_wc_credit_submit_form_reset('credit')"><?php _e('Reset credit', 'wc-term-credit'); ?></button>
          </p>
          <br>
          <span class="description"><?php _e('Balance credit you have', 'wc-term-credit'); ?></span>
        </td>
      </tr>
      <tr>
        <th><label for="credit_term"><?php _e('Credit Term', 'wc-term-credit'); ?></label></th>
        <td>
          <?php $term = get_user_meta($user->ID, 'wc_credit_term', true); ?>
          <select name="credit_term">
            <?php 
            $term_days_arr = $tcp_wc_credit_term->get_credit_term_options();
            foreach ($term_days_arr as $value):
            ?>
              <option value="<?php echo $value; ?>"<?php echo $term == $value ? ' selected' : ''; ?>>
                <?php if ($value == 'bb') {
                    _e('Bill-to-bill', 'wc-term-credit');
                  } else if ($value == 0) {
                    _e('Disabled', 'wc-term-credit');
                  } else {
                    echo $value;
                  } ?>
              </option>
            <?php endforeach; ?>
          </select>
          <br>
          <span class="description"><?php _e('Number of days before credit is due for payment', 'wc-term-credit'); ?></span>
        </td>
      </tr>
      <tr>
        <th><label for="credit_due"><?php _e('Credit Due', 'wc-term-credit'); ?></label></th>
        <td>
          <p id="credit_due">
            <?php
            $due = get_user_meta($user->ID, 'wc_credit_due', true);
            if (!empty($due)):
              $d = new DateTime();
              $d->setTimestamp($due);
              $tz = timezone_open($tcp_wc_credit_term->get_timezone());
              if (!empty($tz)) {
                $d->setTimezone($tz);
              }
              echo esc_attr($d->format('r')); 
              ?>
              <button type="button" class="button" onclick="tcp_wc_credit_submit_form_reset('due')"><?php _e('Reset due date', 'wc-term-credit'); ?></button>
            <?php else: ?>-<?php endif; ?>
          </p>
          <br>
          <span class="description"><?php _e('Date when your credit is due to be paid', 'wc-term-credit'); ?></span>
        </td>
      </tr>
    </table>
    <script>
      function tcp_wc_credit_submit_form_reset(type) {
        if (type != 'credit' && type != 'due') {
          return;
        }
        var form = document.createElement('form');
        form.method = 'post';
        form.action = '<?php echo esc_attr('admin-post.php'); ?>';

        var action = document.createElement('input');
        action.name = 'action';
        action.value = 'reset_' + type;
        form.appendChild(action);

        var user_id = document.createElement('input');
        user_id.name = 'user_id';
        user_id.value = '<?php echo $user->ID; ?>';
        form.appendChild(user_id);

        document.body.appendChild(form);
        form.submit();
      }
    </script>
    <?php
  }

  // save changes when edit user profile
  function user_profile_save($user_id) {
    global $tcp_wc_credit_term;
    if (!current_user_can('edit_user', $user_id)) {
      return false;
    }
    if (isset($_POST['credit_limit'])) {
      $limit = (int) $_POST['credit_limit'];
      if ($limit > 0) {
        update_user_meta($user_id, 'wc_credit_limit', $limit);
      }
    }
    if (isset($_POST['credit_term'])) {
      $term = sanitize_text_field($_POST['credit_term']);
      $term_days_arr = $tcp_wc_credit_term->get_credit_term_options();
      if (in_array($term, $term_days_arr)) {
        update_user_meta($user_id, 'wc_credit_term', $term);
      }
    }
  }

  // reset customer used credit back to 0
  function reset_credit() {
    $user_id = (int) isset($_POST['user_id']) ? $_POST['user_id'] : 0;
    $url = admin_url('users.php');
    if (!empty($user_id)) {
      update_user_meta($user_id, 'wc_credit_used', 0);
      update_user_meta($user_id, 'wc_credit_used_over', 0);
      set_transient('wc_credit_credit_reset', true);
      delete_user_meta($user_id, 'wc_credit_paid_owed');
      $url = get_edit_user_link($user_id);
    }
    wp_redirect($url);
    exit;
  }

  // reset customer due date back to 0
  function reset_due() {
    $user_id = (int) isset($_POST['user_id']) ? $_POST['user_id'] : 0;
    $url = admin_url('users.php');
    if (!empty($user_id)) {
      update_user_meta($user_id, 'wc_credit_due', 0);
      set_transient('wc_credit_due_reset', true);
      $url = get_edit_user_link($user_id);
    }
    wp_redirect($url);
    exit;
  }

}

new TCP_wc_credit_term_profile();