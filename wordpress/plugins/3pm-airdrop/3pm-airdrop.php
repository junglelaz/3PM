<?php
/**
 * Plugin Name: 3PM Airdrop Orchestrator
 * Description: Collect Solana wallets, tag digital purchases for future NFT airdrops, review/export items, and expose a REST feed for your minting script.
 * Version: 1.0.2
 * Author: 3PM
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

class ThreePM_Airdrop_Plugin {
    const VERSION = '1.0.2';
    const NONCE   = 'threepm_airdrop_nonce';

    public function __construct() {
        // User account field
        add_action('woocommerce_edit_account_form', [$this,'account_field']);
        add_action('woocommerce_save_account_details_errors', [$this,'account_field_validate']);
        add_action('woocommerce_save_account_details', [$this,'account_field_save']);

        // (Optional) Checkout capture
        add_action('woocommerce_after_order_notes', [$this,'checkout_field']);
        add_action('woocommerce_checkout_process', [$this,'checkout_field_validate']);
        add_action('woocommerce_checkout_update_user_meta', [$this,'checkout_field_save_user_meta'], 10, 2);

        // Product NFT Blueprint metabox
        add_action('add_meta_boxes', [$this,'product_metabox']);
        add_action('save_post_product', [$this,'save_product_metabox'], 10, 2);

        // Stamp order item meta for downloadable products
        add_action('woocommerce_checkout_create_order_line_item', [$this,'stamp_order_item_meta'], 10, 4);

        // Admin screen + CSV
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'maybe_export_csv']);

        // REST route
        add_action('rest_api_init', [$this,'register_rest_routes']);

        // WP-CLI export
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('threepm-airdrop export', [$this,'wpcli_export']);
        }
    }

    /* -------------------------
     * 1) USER ACCOUNT FIELD
     * ------------------------- */
    public function account_field() {
        $uid = get_current_user_id();
        if (!$uid) return;
        $w = get_user_meta($uid, 'solana_wallet', true);
        ?>
        <p class="form-row form-row-wide">
            <label for="solana_wallet">Solana Wallet Address&nbsp;<span class="optional">(Phantom/Backpack)</span></label>
            <input
                type="text"
                name="solana_wallet"
                id="solana_wallet"
                class="woocommerce-Input input-text"
                value="<?php echo esc_attr($w); ?>"
                placeholder="Your Solana address"
                autocomplete="off"
            />
            <small class="woocommerce-form__input-description">
                Used later to airdrop your 3PM NFTs. Base58, 32–44 chars.
            </small>
        </p>
        <?php
        wp_nonce_field(self::NONCE, self::NONCE);
    }

    public function account_field_validate($errors) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (isset($_POST['solana_wallet']) && $_POST['solana_wallet'] !== '') {
            $addr = trim(wp_unslash($_POST['solana_wallet']));
            if (!$this->is_likely_solana($addr)) {
                $errors->add('sol_addr_err','Please enter a valid Solana address (base58, 32–44 chars).');
            }
        }
    }

    public function account_field_save($user_id) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (isset($_POST['solana_wallet'])) {
            update_user_meta($user_id, 'solana_wallet', sanitize_text_field(wp_unslash($_POST['solana_wallet'])));
        }
    }

    /* -------------------------
     * 2) CHECKOUT FIELD (OPTIONAL)
     * ------------------------- */
    public function checkout_field($checkout) {
        echo '<div id="threepm_checkout_wallet"><h3>Solana Wallet (for future NFT airdrops)</h3>';
        woocommerce_form_field('solana_wallet_checkout', [
            'type'        => 'text',
            'required'    => false,
            'label'       => 'Solana Wallet Address',
            'placeholder' => 'Your Solana address',
            'class'       => ['form-row','form-row-wide'],
            'input_class' => ['woocommerce-Input','input-text'],
        ], WC()->customer ? get_user_meta(get_current_user_id(), 'solana_wallet', true) : '');
        echo '</div>';
    }

    public function checkout_field_validate() {
        if (isset($_POST['solana_wallet_checkout']) && $_POST['solana_wallet_checkout'] !== '') {
            $addr = trim(wp_unslash($_POST['solana_wallet_checkout']));
            if (!$this->is_likely_solana($addr)) {
                wc_add_notice(__('Please enter a valid Solana address (base58, 32–44 chars).'), 'error');
            }
        }
    }

    public function checkout_field_save_user_meta($customer_id, $posted) {
        if (!$customer_id) return;
        if (isset($_POST['solana_wallet_checkout']) && $_POST['solana_wallet_checkout'] !== '') {
            $addr = sanitize_text_field(wp_unslash($_POST['solana_wallet_checkout']));
            if ($this->is_likely_solana($addr)) {
                update_user_meta($customer_id, 'solana_wallet', $addr);
            }
        }
    }

    private function is_likely_solana($addr) {
        // Base58 without 0OI l (common form), 32–44 chars
        return (bool) preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $addr);
    }

    /* -------------------------
     * 3) PRODUCT METABOX
     * ------------------------- */
    public function product_metabox() {
        add_meta_box(
            'threepm_nft_blueprint',
            '3PM NFT Blueprint',
            [$this,'render_product_metabox'],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox($post) {
        wp_nonce_field(self::NONCE, self::NONCE);
        $bp = get_post_meta($post->ID, '_3pm_nft_blueprint', true);
        ?>
        <p>
            <label for="_3pm_nft_blueprint"><strong>NFT Blueprint</strong></label>
            <textarea style="width:100%;height:100px;" id="_3pm_nft_blueprint" name="_3pm_nft_blueprint" placeholder="e.g. metadata URL, blueprint key, or JSON blob"><?php echo esc_textarea($bp); ?></textarea>
        </p>
        <p class="description">This value is saved on each order item at purchase for future airdrop mapping.</p>
        <?php
    }

    public function save_product_metabox($post_id, $post) {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], self::NONCE)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type !== 'product' || !current_user_can('edit_post', $post_id)) return;
        if (isset($_POST['_3pm_nft_blueprint'])) {
            update_post_meta($post_id, '_3pm_nft_blueprint', wp_kses_post(wp_unslash($_POST['_3pm_nft_blueprint'])));
        }
    }

    /* -------------------------
     * 4) ORDER ITEM META
     * ------------------------- */
    public function stamp_order_item_meta($item, $cart_item_key, $values, $order) {
        $product = $item->get_product();
        if (!$product) return;

        if ($product->is_downloadable()) {
            $user_id  = $order->get_user_id();
            $wallet   = $user_id ? get_user_meta($user_id, 'solana_wallet', true) : '';
            $checkout_wallet = isset($_POST['solana_wallet_checkout']) ? sanitize_text_field(wp_unslash($_POST['solana_wallet_checkout'])) : '';
            if ($this->is_likely_solana($checkout_wallet)) $wallet = $checkout_wallet;
            $blueprint= get_post_meta($product->get_id(), '_3pm_nft_blueprint', true);

            $item->add_meta_data('_3pm_airdrop_eligible', 'yes');
            $item->add_meta_data('_3pm_product_id', $product->get_id());
            $item->add_meta_data('_3pm_product_sku', $product->get_sku());
            $item->add_meta_data('_3pm_user_id', $user_id);
            $item->add_meta_data('_3pm_wallet_snapshot', $wallet);
            $item->add_meta_data('_3pm_nft_blueprint', $blueprint);
            $item->add_meta_data('_3pm_airdropped', 'no');
            $item->add_meta_data('_3pm_airdropped_at', '');
            $item->add_meta_data('_3pm_airdrop_sig', '');
        }
    }

    /* -------------------------
     * 5) ADMIN PAGE + CSV EXPORT
     * ------------------------- */
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            '3PM Airdrops',
            '3PM Airdrops',
            'manage_woocommerce',
            'threepm-airdrops',
            [$this,'render_admin']
        );
    }

    public function render_admin() {
        if (!current_user_can('manage_woocommerce')) return;

        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
        $paged         = max(1, intval($_GET['paged'] ?? 1));
        $per_page      = 25;

        $orders = $this->order_stream('DESC', $status_filter);

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $eligible = wc_get_order_item_meta($item_id, '_3pm_airdrop_eligible', true);
                if ($eligible !== 'yes') continue;

                $airdropped = wc_get_order_item_meta($item_id, '_3pm_airdropped', true);
                if ($airdropped !== 'yes' && !$this->can_fulfil($order, $item_id)) continue;
                if ($status_filter === 'pending' && $airdropped === 'yes') continue;
                if ($status_filter === 'done'    && $airdropped !== 'yes') continue;

                $rows[] = [
                    'order_id'        => $order->get_id(),
                    'order_date'      => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
                    'customer'        => $order->get_formatted_billing_full_name(),
                    'email'           => $order->get_billing_email(),
                    'item_id'         => $item_id,
                    'product'         => $item->get_name(),
                    'sku'             => wc_get_order_item_meta($item_id, '_3pm_product_sku', true),
                    'user_id'         => wc_get_order_item_meta($item_id, '_3pm_user_id', true),
                    'wallet_snapshot' => wc_get_order_item_meta($item_id, '_3pm_wallet_snapshot', true),
                    'blueprint'       => wc_get_order_item_meta($item_id, '_3pm_nft_blueprint', true),
                    'airdropped'      => $airdropped,
                    'airdrop_sig'     => wc_get_order_item_meta($item_id, '_3pm_airdrop_sig', true),
                    'airdropped_at'   => wc_get_order_item_meta($item_id, '_3pm_airdropped_at', true),
                ];
            }
        }

        $total = count($rows);
        $pages = max(1, ceil($total / $per_page));
        $offset= ($paged - 1) * $per_page;
        $slice = array_slice($rows, $offset, $per_page);

        $base_url = admin_url('admin.php?page=threepm-airdrops');
        $export_url = wp_nonce_url(add_query_arg(['threepm_export' => $status_filter], $base_url), self::NONCE);
        ?>
        <div class="wrap">
            <h1>3PM Airdrops</h1>

            <p>
                <a class="button <?php echo $status_filter==='pending'?'button-primary':''; ?>" href="<?php echo esc_url(add_query_arg(['status'=>'pending'], $base_url)); ?>">Pending</a>
                <a class="button <?php echo $status_filter==='done'?'button-primary':''; ?>" href="<?php echo esc_url(add_query_arg(['status'=>'done'], $base_url)); ?>">Airdropped</a>
                <a class="button" href="<?php echo esc_url($export_url); ?>">Export <?php echo esc_html(ucfirst($status_filter)); ?> CSV</a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Order</th><th>Date</th><th>Customer</th><th>Email</th>
                        <th>Item ID</th><th>Product</th><th>SKU</th>
                        <th>User ID</th><th>Wallet Snapshot</th><th>Blueprint</th>
                        <th>Status</th><th>Tx Sig</th><th>Airdropped At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$slice): ?>
                        <tr><td colspan="13">No records.</td></tr>
                    <?php else: foreach ($slice as $r): ?>
                        <tr>
                            <td><a href="<?php echo esc_url(get_edit_post_link($r['order_id'])); ?>">#<?php echo intval($r['order_id']); ?></a></td>
                            <td><?php echo esc_html($r['order_date']); ?></td>
                            <td><?php echo esc_html($r['customer']); ?></td>
                            <td><?php echo esc_html($r['email']); ?></td>
                            <td><?php echo intval($r['item_id']); ?></td>
                            <td><?php echo esc_html($r['product']); ?></td>
                            <td><?php echo esc_html($r['sku']); ?></td>
                            <td><?php echo esc_html($r['user_id']); ?></td>
                            <td><?php echo esc_html($r['wallet_snapshot']); ?></td>
                            <td style="max-width:260px;word-break:break-word;"><?php echo esc_html($r['blueprint']); ?></td>
                            <td><?php echo $r['airdropped']==='yes' ? '✅' : '⏳'; ?></td>
                            <td style="font-family:monospace;"><?php echo esc_html($r['airdrop_sig']); ?></td>
                            <td><?php echo esc_html($r['airdropped_at']); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <p>
                <?php for ($p=1; $p<=$pages; $p++): ?>
                    <a class="button <?php echo $p===$paged?'button-primary':''; ?>"
                       href="<?php echo esc_url(add_query_arg(['status'=>$status_filter,'paged'=>$p], $base_url)); ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </p>
        </div>
        <?php
    }

    public function maybe_export_csv() {
        if (!is_admin()) return;
        if (!isset($_GET['threepm_export'])) return;
        if (!current_user_can('manage_woocommerce')) return;
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', self::NONCE)) return;

        $which = sanitize_text_field($_GET['threepm_export']); // 'pending' or 'done'

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=3pm-airdrops-'.$which.'-'.date('Ymd-His').'.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['order_id','order_date','customer','email','item_id','product','sku','user_id','wallet_snapshot','blueprint','airdropped','airdrop_sig','airdropped_at']);

        $orders = $this->order_stream('DESC', $which);

        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $eligible = wc_get_order_item_meta($item_id, '_3pm_airdrop_eligible', true);
                if ($eligible !== 'yes') continue;
                $airdropped = wc_get_order_item_meta($item_id, '_3pm_airdropped', true);
                if ($airdropped !== 'yes' && !$this->can_fulfil($order, $item_id)) continue;
                if ($which === 'pending' && $airdropped === 'yes') continue;
                if ($which === 'done'    && $airdropped !== 'yes') continue;

                fputcsv($out, [
                    $order->get_id(),
                    $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
                    $order->get_formatted_billing_full_name(),
                    $order->get_billing_email(),
                    $item_id,
                    $item->get_name(),
                    wc_get_order_item_meta($item_id, '_3pm_product_sku', true),
                    wc_get_order_item_meta($item_id, '_3pm_user_id', true),
                    wc_get_order_item_meta($item_id, '_3pm_wallet_snapshot', true),
                    wc_get_order_item_meta($item_id, '_3pm_nft_blueprint', true),
                    $airdropped,
                    wc_get_order_item_meta($item_id, '_3pm_airdrop_sig', true),
                    wc_get_order_item_meta($item_id, '_3pm_airdropped_at', true),
                ]);
            }
        }
        fclose($out);
        exit;
    }

    /* -------------------------
     * 6) REST API (for your mint script)
     * ------------------------- */
    public function register_rest_routes() {
        register_rest_route('threepm-airdrop/v1', '/pending', [
            'methods'  => 'GET',
            'callback' => [$this,'rest_pending'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce') || $this->token_ok();
            }
        ]);

        register_rest_route('threepm-airdrop/v1', '/mark', [
            'methods'  => 'POST',
            'callback' => [$this,'rest_mark'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce') || $this->token_ok();
            }
        ]);
    }

    // Simple bearer token support via constant in wp-config.php: define('THREEPM_AIRDROP_TOKEN','yourlongtoken');
    private function token_ok() {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($hdr), $matches)) {
            $tok = $matches[1];
            $conf= defined('THREEPM_AIRDROP_TOKEN') ? constant('THREEPM_AIRDROP_TOKEN') : '';
            return $conf && hash_equals($conf, $tok);
        }
        return false;
    }

    public function rest_pending(\WP_REST_Request $req) {
        $limit = max(1, min(1000, intval($req->get_param('limit') ?: 500)));
        $rows  = [];

        $orders = $this->order_stream('ASC', 'pending');
        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                if (wc_get_order_item_meta($item_id, '_3pm_airdrop_eligible', true) !== 'yes') continue;
                if (wc_get_order_item_meta($item_id, '_3pm_airdropped', true) === 'yes') continue;
                if (!$this->can_fulfil($order, $item_id)) continue;

                $rows[] = [
                    'order_id'  => $order->get_id(),
                    'item_id'   => $item_id,
                    'user_id'   => wc_get_order_item_meta($item_id, '_3pm_user_id', true),
                    'email'     => $order->get_billing_email(),
                    'product'   => $item->get_name(),
                    'sku'       => wc_get_order_item_meta($item_id, '_3pm_product_sku', true),
                    'wallet'    => wc_get_order_item_meta($item_id, '_3pm_wallet_snapshot', true) ?: get_user_meta($order->get_user_id(), 'solana_wallet', true),
                    'blueprint' => wc_get_order_item_meta($item_id, '_3pm_nft_blueprint', true),
                    'order_date'=> $order->get_date_created() ? $order->get_date_created()->date('c') : '',
                ];
                if (count($rows) >= $limit) break 2;
            }
        }

        return new \WP_REST_Response(['count'=>count($rows), 'items'=>$rows], 200);
    }

    // Bounded order batches keep exports and the mint feed from loading every order at once.
    private function order_stream($direction = 'DESC', $which = 'all') {
        $page = 1;
        do {
            $orders = wc_get_orders([
                'limit' => 100,
                'page' => $page++,
                'orderby' => 'ID',
                'order' => $direction,
                'return' => 'objects',
                'status' => $which === 'pending' ? wc_get_is_paid_statuses() : array_keys(wc_get_order_statuses()),
            ]);
            foreach ($orders as $order) yield $order;
        } while (count($orders) === 100);
    }

    private function can_fulfil($order, $item_id) {
        return $order && $order->is_paid() && (float) $order->get_qty_refunded_for_item($item_id) === 0.0;
    }

    public function rest_mark(\WP_REST_Request $req) {
        $item_id = intval($req->get_param('item_id'));
        $sig     = sanitize_text_field($req->get_param('tx_sig'));
        $time    = current_time('mysql');

        if (!$item_id || !$sig) {
            return new \WP_REST_Response(['ok'=>false,'error'=>'item_id and tx_sig required'], 400);
        }
        $order_id = wc_get_order_id_by_order_item_id($item_id);
        $order = $order_id ? wc_get_order($order_id) : false;
        $item = $order ? $order->get_item($item_id) : false;
        if (!$item || !is_a($item, 'WC_Order_Item_Product') || $item->get_meta('_3pm_airdrop_eligible', true) !== 'yes') {
            return new \WP_REST_Response(['ok'=>false,'error'=>'Eligible order item not found'], 404);
        }
        if ($item->get_meta('_3pm_airdropped', true) === 'yes') {
            $same = hash_equals((string) $item->get_meta('_3pm_airdrop_sig', true), $sig);
            return new \WP_REST_Response(['ok'=>$same,'error'=>$same ? null : 'Item already fulfilled with another signature'], $same ? 200 : 409);
        }
        if (!$this->can_fulfil($order, $item_id)) {
            return new \WP_REST_Response(['ok'=>false,'error'=>'Order must be paid and item must not be refunded'], 409);
        }
        wc_update_order_item_meta($item_id, '_3pm_airdropped', 'yes');
        wc_update_order_item_meta($item_id, '_3pm_airdrop_sig', $sig);
        wc_update_order_item_meta($item_id, '_3pm_airdropped_at', $time);
        return new \WP_REST_Response(['ok'=>true], 200);
    }

    /* -------------------------
     * 7) WP-CLI: export to CSV
     * ------------------------- */
    public function wpcli_export($args, $assoc_args) {
        $status = $assoc_args['status'] ?? 'pending'; // pending|done
        $file   = $assoc_args['file']   ?? ('threepm-airdrops-'.$status.'-'.date('Ymd-His').'.csv');

        $fh = fopen($file, 'w');
        if (!$fh) { WP_CLI::error('Cannot open file for writing.'); }
        fputcsv($fh, ['order_id','order_date','customer','email','item_id','product','sku','user_id','wallet_snapshot','blueprint','airdropped','airdrop_sig','airdropped_at']);

        $orders = $this->order_stream('DESC', $status);

        $count=0;
        foreach ($orders as $order) {
            foreach ($order->get_items('line_item') as $item_id => $item) {
                $eligible  = wc_get_order_item_meta($item_id, '_3pm_airdrop_eligible', true);
                if ($eligible !== 'yes') continue;
                $airdropped= wc_get_order_item_meta($item_id, '_3pm_airdropped', true);
                if ($airdropped !== 'yes' && !$this->can_fulfil($order, $item_id)) continue;
                if ($status === 'pending' && $airdropped === 'yes') continue;
                if ($status === 'done'    && $airdropped !== 'yes') continue;

                fputcsv($fh, [
                    $order->get_id(),
                    $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '',
                    $order->get_formatted_billing_full_name(),
                    $order->get_billing_email(),
                    $item_id,
                    $item->get_name(),
                    wc_get_order_item_meta($item_id, '_3pm_product_sku', true),
                    wc_get_order_item_meta($item_id, '_3pm_user_id', true),
                    wc_get_order_item_meta($item_id, '_3pm_wallet_snapshot', true),
                    wc_get_order_item_meta($item_id, '_3pm_nft_blueprint', true),
                    $airdropped,
                    wc_get_order_item_meta($item_id, '_3pm_airdrop_sig', true),
                    wc_get_order_item_meta($item_id, '_3pm_airdropped_at', true),
                ]);
                $count++;
            }
        }
        fclose($fh);
        WP_CLI::success("Exported {$count} rows to {$file}");
    }
}

new ThreePM_Airdrop_Plugin();
