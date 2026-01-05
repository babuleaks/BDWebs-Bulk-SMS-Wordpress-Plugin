<?php
/**
 * Plugin Name: BDWebs SMS Plugin
 * Description: Bulk SMS and WooCommerce Notifications. Features Auto-prefix 88, Responsive UI, and Bangla Unicode Support.
 * Version: 14.0
 * Author: Sajjad Hossain For BDWebs.com
 */

if (!defined('ABSPATH')) exit;

class BDWebsSMSPlugin {

    public function __construct() {
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('wp_ajax_save_bd_pro', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_get_bd_balance', [$this, 'ajax_get_balance']);
        add_action('wp_ajax_send_manual_sms', [$this, 'ajax_send_manual_sms']);
        
        // WooCommerce Hooks
        add_action('woocommerce_order_status_processing', [$this, 'trigger_order_sms'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'trigger_order_sms'], 10, 1);
    }

    public function create_menu() {
        add_menu_page('BDWebs SMS', 'BDWebs SMS', 'manage_options', 'bdwebs-sms', [$this, 'admin_page'], 'dashicons-email-alt');
    }

    /**
     * Helper: Format Numbers to 88XXXXXXXXXXX
     */
    private function format_numbers($input) {
        $numbers = explode(',', $input);
        $formatted = array();
        foreach ($numbers as $num) {
            $num = trim($num);
            // Auto prefix 88 if it starts with 01 and is 11 digits
            if (strpos($num, '01') === 0 && strlen($num) === 11) {
                $num = '88' . $num;
            }
            $formatted[] = $num;
        }
        return implode(',', $formatted);
    }

    /**
     * Helper: Check if string contains Bangla/Unicode
     */
    private function is_unicode($string) {
        return (strlen($string) != strlen(utf8_decode($string)));
    }

    private function call_api($endpoint, $args = []) {
        $k = get_option('asms_key');
        $c = get_option('asms_client');
        if (!$k || !$c) return false;

        $url = "http://sms.bdwebs.com/api/v2/$endpoint?ApiKey=".rawurlencode($k)."&ClientId=".rawurlencode($c);
        foreach ($args as $key => $val) { $url .= "&$key=".rawurlencode($val); }

        $res = wp_remote_get($url, ['timeout' => 25, 'sslverify' => false]);
        return is_wp_error($res) ? false : wp_remote_retrieve_body($res);
    }

    public function ajax_save_settings() {
        check_ajax_referer('bd_pro_nonce', 'security');
        update_option('asms_key', sanitize_text_field($_POST['k']));
        update_option('asms_client', sanitize_text_field($_POST['c']));
        update_option('asms_sender', sanitize_text_field($_POST['s']));
        update_option('asms_t1', sanitize_text_field($_POST['t1']));
        update_option('asms_t2', sanitize_text_field($_POST['t2']));
        wp_send_json_success();
    }

    public function ajax_get_balance() {
        $res = $this->call_api('Balance');
        $data = json_decode($res, true);
        wp_send_json_success($data['Data'][0]['Credits'] ?? '0.00');
    }

    /**
     * Manual SMS with Bangla/Unicode Handling
     */
    public function ajax_send_manual_sms() {
        check_ajax_referer('bd_pro_nonce', 'security');
        $num = $this->format_numbers(sanitize_text_field($_POST['n']));
        $msg = sanitize_textarea_field($_POST['m']);
        
        $unicode = $this->is_unicode($msg) ? 'true' : 'false';
        $res = $this->call_api('SendSMS', [
            'SenderId' => get_option('asms_sender'), 
            'Message' => $msg, 
            'MobileNumbers' => $num,
            'Is_Unicode' => $unicode,
            'DataCoding' => ($unicode == 'true' ? '8' : '0')
        ]);

        $data = json_decode($res, true);
        $html = '<div style="margin-top:20px;">';
        
        if (isset($data['ErrorCode']) && $data['ErrorCode'] == 0 && !empty($data['Data'])) {
            foreach ($data['Data'] as $report) {
                $is_success = ($report['MessageErrorDescription'] === 'Success');
                $bg = $is_success ? '#d4edda' : '#f8d7da';
                $color = $is_success ? '#155724' : '#721c24';
                $html .= "<div style='padding:12px; margin-bottom:8px; border-radius:4px; background:$bg; color:$color; border:1px solid rgba(0,0,0,0.05);'>";
                $html .= "<strong>Number:</strong> {$report['MobileNumber']} | <strong>Status:</strong> {$report['MessageErrorDescription']}";
                if($is_success) $html .= "<br><small>Message ID: {$report['MessageId']}</small>";
                $html .= "</div>";
            }
        } else {
            $html .= '<div style="padding:12px; background:#f8d7da; color:#721c24; border-radius:4px;">Error: ' . ($data['ErrorDescription'] ?? 'Unknown API Error') . '</div>';
        }
        $html .= '</div>';

        wp_send_json_success($html);
    }

    /**
     * WooCommerce Automation with Tag Fetching & Unicode
     */
    public function trigger_order_sms($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $status      = $order->get_status();
        $phone       = $this->format_numbers($order->get_billing_phone());
        $order_total = $order->get_total();
        $first_name  = $order->get_billing_first_name();
        
        $template = ($status == 'processing') ? get_option('asms_t1') : get_option('asms_t2');
        
        if ($phone && $template) {
            $search  = array('[ID]', '{order_id}', '{order_total}', '{first_name}');
            $replace = array($order_id, $order_id, $order_total, $first_name);
            $msg     = str_replace($search, $replace, $template);
            
            $unicode = $this->is_unicode($msg) ? 'true' : 'false';

            $this->call_api('SendSMS', [
                'SenderId'      => get_option('asms_sender'), 
                'Message'       => $msg, 
                'MobileNumbers' => $phone,
                'Is_Unicode'    => $unicode,
                'DataCoding'    => ($unicode == 'true' ? '8' : '0')
            ]);
        }
    }

    public function admin_page() {
        ?>
        <style>
            .bd-header { display:flex; justify-content:space-between; align-items:center; background:#2c3338; color:#fff; padding:15px 25px; border-radius:5px; margin-bottom:20px; flex-wrap: wrap; gap: 10px; }
            .badge-bal { background:#27ae60; padding:6px 14px; border-radius:4px; font-weight:bold; }
            .bd-card { background:#fff; border:1px solid #ccd0d4; padding:25px; border-radius:5px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
            .bd-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; }
            .bd-input { width:100%; padding:10px; border:1px solid #8c8f94; border-radius:4px; margin-bottom:15px; box-sizing: border-box; }
            @media (max-width: 600px) { .bd-header { text-align: center; justify-content: center; } }
        </style>

        <div class="wrap">
            <div class="bd-header">
                <h2 style="color:#fff; margin:0;">BDWebs SMS Control Panel</h2>
                <div>Balance: <span class="badge-bal" id="live-bal">Updating...</span></div>
            </div>

            <div class="bd-grid">
                <div class="bd-card">
                    <h3>API & WooCommerce Settings</h3>
                    <label><b>API Key</b></label><input type="password" id="k" value="<?php echo esc_attr(get_option('asms_key')); ?>" class="bd-input">
                    <label><b>Client ID</b></label><input type="text" id="c" value="<?php echo esc_attr(get_option('asms_client')); ?>" class="bd-input">
                    <label><b>Sender ID</b></label><input type="text" id="s" value="<?php echo esc_attr(get_option('asms_sender')); ?>" class="bd-input">
                    <hr>
                    <p><small>Tags: <b>{order_id}</b>, <b>{order_total}</b>, <b>{first_name}</b></small></p>
                    <label><b>Processing Message</b></label><textarea id="t1" class="bd-input" rows="2"><?php echo esc_textarea(get_option('asms_t1')); ?></textarea>
                    <label><b>Completed Message</b></label><textarea id="t2" class="bd-input" rows="2"><?php echo esc_textarea(get_option('asms_t2')); ?></textarea>
                    <button id="save-all" class="button button-primary">Save All Settings</button>
                </div>

                <div class="bd-card">
                    <h3>Quick Send SMS (Unicode/Bangla Support)</h3>
                    <label><b>Phone Number(s)</b></label>
                    <input type="text" id="qn" placeholder="01XXXXXXXXX or 8801XXXXXXXXX" class="bd-input">
                    <label><b>Message Content</b></label>
                    <textarea id="qm" class="bd-input" rows="6"></textarea>
                    <button id="send-btn" class="button button-secondary">Send Message Now</button>
                    <div id="quick-res"></div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Load Balance
            function refreshBal() {
                $.post(ajaxurl, {action: 'get_bd_balance'}, function(r) {
                    if(r.success) $('#live-bal').text(r.data + ' BDT');
                });
            }
            refreshBal();

            // Save Settings
            $('#save-all').click(function() {
                var btn = $(this).text('Saving...');
                $.post(ajaxurl, {
                    action: 'save_bd_pro', security: '<?php echo wp_create_nonce("bd_pro_nonce"); ?>',
                    k: $('#k').val(), c: $('#c').val(), s: $('#s').val(), t1: $('#t1').val(), t2: $('#t2').val()
                }, function() { btn.text('Save All Settings'); refreshBal(); alert('Settings Saved!'); });
            });

            // Send SMS
            $('#send-btn').click(function() {
                var btn = $(this).text('Sending...');
                $('#quick-res').html('');
                $.post(ajaxurl, {
                    action: 'send_manual_sms', security: '<?php echo wp_create_nonce("bd_pro_nonce"); ?>',
                    n: $('#qn').val(), m: $('#qm').val()
                }, function(r) {
                    btn.text('Send Message Now');
                    $('#quick-res').html(r.data);
                    refreshBal();
                });
            });
        });
        </script>
        <?php
    }
}
new BDWebsSMSPlugin();
