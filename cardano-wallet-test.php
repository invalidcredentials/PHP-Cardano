<?php
/**
 * Plugin Name: PHP Cardano Utilities by PB
 * Description: Pure PHP implementation of Cardano wallet generation and transaction signing - NO external dependencies
 * Version: 1.0.0
 * Author: PB
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Debug: Log plugin load
error_log('Cardano Wallet Test plugin loaded!');

// Add admin menu
add_action('admin_menu', function() {
    error_log('Cardano Wallet Test: Adding admin menu');
    add_menu_page(
        'Cardano Wallet Test',
        'Wallet Test',
        'manage_options',
        'cardano-wallet-test',
        'cardano_wallet_test_page',
        'dashicons-admin-network'
    );
});

function cardano_wallet_test_page() {
    // Start output buffering to catch any stray output before redirects
    ob_start();

    // Determine which tab to redirect back to (detect from referer or default to configuration)
    $redirect_tab = 'configuration';
    if (isset($_POST['redirect_tab'])) {
        $redirect_tab = sanitize_text_field($_POST['redirect_tab']);
    } elseif (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'tab=') !== false) {
        // Extract tab from referer URL
        parse_str(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY), $query_params);
        if (isset($query_params['tab'])) {
            $redirect_tab = sanitize_text_field($query_params['tab']);
        }
    }

    // ========================================================================
    // HANDLE ALL POST REQUESTS FIRST (before any output!)
    // ========================================================================

    // Save Anvil API Keys (both mainnet and preprod)
    if (isset($_POST['save_anvil_keys'])) {
        check_admin_referer('save_anvil_keys');
        $mainnet_key = sanitize_text_field($_POST['anvil_api_key_mainnet']);
        $preprod_key = sanitize_text_field($_POST['anvil_api_key_preprod']);
        update_option('cardano_wallet_test_anvil_key_mainnet', $mainnet_key);
        update_option('cardano_wallet_test_anvil_key_preprod', $preprod_key);

        // Store response in transient
        set_transient('cardano_wallet_response_message', '✅ API keys saved successfully!', 300); // 5 minutes
        set_transient('cardano_wallet_response_type', 'success', 300);

        // Clean buffer and use JavaScript redirect (more reliable in WordPress admin)
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=' . $redirect_tab) . '";</script>';
        exit;
    }

    // Test Anvil API Connection
    if (isset($_POST['test_anvil_connection'])) {
        check_admin_referer('test_anvil_connection');
        $network = $_POST['test_network'] ?? 'preprod';

        // Get the correct API key based on network
        $api_key = $network === 'mainnet'
            ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
            : get_option('cardano_wallet_test_anvil_key_preprod', '');

        if (empty($api_key)) {
            set_transient('cardano_wallet_response_message', '❌ No ' . $network . ' API key saved. Please add your API key first.', 300);
            set_transient('cardano_wallet_response_type', 'error', 300);
        } else {
            $api_url = $network === 'mainnet'
                ? 'https://prod.api.ada-anvil.app/v2/services'
                : 'https://preprod.api.ada-anvil.app/v2/services';

            // Use health endpoint to test API key validity
            $response = wp_remote_get($api_url . '/health', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $api_key
                ],
                'timeout' => 15
            ]);

            if (is_wp_error($response)) {
                set_transient('cardano_wallet_response_message', '❌ Connection Error: ' . $response->get_error_message(), 300);
                set_transient('cardano_wallet_response_type', 'error', 300);
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);

                if ($status_code === 401 || $status_code === 403) {
                    $formatted_response = '<strong>Endpoint:</strong> ' . esc_html($api_url . '/health') . '<br>';
                    $formatted_response .= '<strong>Status Code:</strong> ' . $status_code . '<br>';
                    $formatted_response .= '<strong>Response:</strong> ' . esc_html($body);

                    set_transient('cardano_wallet_response_message', '❌ Authentication Failed - Invalid API key for ' . $network, 300);
                    set_transient('cardano_wallet_response_type', 'error', 300);
                    set_transient('cardano_wallet_response_data', ['formatted' => $formatted_response], 300);
                } elseif ($status_code === 200) {
                    $formatted_response = '<strong>✅ Connection Successful!</strong><br><br>';
                    $formatted_response .= '<strong>Network:</strong> ' . esc_html(ucfirst($network)) . '<br>';
                    $formatted_response .= '<strong>Endpoint:</strong> ' . esc_html($api_url . '/health') . '<br>';
                    $formatted_response .= '<strong>Status Code:</strong> ' . $status_code . '<br><br>';
                    $formatted_response .= '<strong>API Response:</strong><br>';
                    $formatted_response .= '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px; margin-top: 5px;">' . esc_html($body) . '</pre>';

                    set_transient('cardano_wallet_response_message', '✅ API Connection Successful! Your ' . $network . ' API key is working correctly.', 300);
                    set_transient('cardano_wallet_response_type', 'success', 300);
                    set_transient('cardano_wallet_response_data', ['formatted' => $formatted_response], 300);
                } else {
                    $formatted_response = '<strong>Endpoint:</strong> ' . esc_html($api_url . '/health') . '<br>';
                    $formatted_response .= '<strong>Status Code:</strong> ' . $status_code . '<br><br>';
                    $formatted_response .= '<pre style="background: #f5f5f5; padding: 10px; border-radius: 3px;">' . esc_html($body) . '</pre>';

                    set_transient('cardano_wallet_response_message', '❌ Unexpected Response from Anvil API', 300);
                    set_transient('cardano_wallet_response_type', 'error', 300);
                    set_transient('cardano_wallet_response_data', ['formatted' => $formatted_response], 300);
                }
            }
        }

        // Clean buffer and use JavaScript redirect (more reliable in WordPress admin)
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=' . $redirect_tab) . '";</script>';
        exit;
    }

    // Save test wallet for persistent testing
    if (isset($_POST['save_test_wallet'])) {
        check_admin_referer('save_test_wallet');

        require_once plugin_dir_path(__FILE__) . 'CardanoWalletPHP.php';

        $network = $_POST['save_network'] ?? 'preprod';

        $start_time = microtime(true);
        $result = CardanoWalletPHP::generateWallet($network);
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);

        if ($result['success']) {
            // Save to database with ALL wallet components
            update_option('cardano_test_wallet', [
                'mnemonic' => $result['mnemonic'],
                'payment_address' => $result['addresses']['payment_address'],
                'stake_address' => $result['addresses']['stake_address'],
                'payment_skey_hex' => $result['payment_skey_hex'],
                'payment_skey_extended' => $result['payment_skey_extended'],
                'payment_pkey_hex' => $result['payment_pkey_hex'],
                'payment_keyhash' => $result['payment_keyhash'],
                'stake_skey_hex' => isset($result['stake_skey_hex']) ? $result['stake_skey_hex'] : '',
                'stake_skey_extended' => isset($result['stake_skey_extended']) ? $result['stake_skey_extended'] : '',
                'stake_keyhash' => isset($result['stake_keyhash']) ? $result['stake_keyhash'] : '',
                'network' => $network,
                'generated_at' => current_time('mysql')
            ]);

            $formatted_response = '<strong>✅ Wallet Generated & Saved!</strong><br><br>';
            $formatted_response .= '<strong>Generation Time:</strong> ' . $elapsed . 'ms<br>';
            $formatted_response .= '<strong>Network:</strong> ' . esc_html(ucfirst($network)) . '<br>';
            $formatted_response .= '<strong>Extended Key Length:</strong> ' . strlen($result['payment_skey_extended']) . ' chars (CIP-1852 compliant)<br><br>';
            $formatted_response .= '<strong>Payment Address:</strong><br>';
            $formatted_response .= '<code style="display: block; background: #f5f5f5; padding: 8px; margin: 5px 0; border-radius: 3px; word-break: break-all; font-size: 11px;">';
            $formatted_response .= esc_html($result['addresses']['payment_address']) . '</code><br>';
            $formatted_response .= '<p style="margin: 10px 0; padding: 10px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 13px;">';
            $formatted_response .= '<strong>📚 What to do next:</strong><br>';
            $formatted_response .= '1️⃣ Fund this address with tADA from the <a href="https://docs.cardano.org/cardano-testnet/tools/faucet/" target="_blank">Cardano Faucet</a><br>';
            $formatted_response .= '2️⃣ Head to the <strong>Transactions</strong> tab to build your first transaction!<br>';
            $formatted_response .= '3️⃣ All wallet details are displayed below for educational purposes';
            $formatted_response .= '</p>';

            set_transient('cardano_wallet_response_message', '✅ Test wallet generated and saved successfully!', 300);
            set_transient('cardano_wallet_response_type', 'success', 300);
            set_transient('cardano_wallet_response_data', ['formatted' => $formatted_response], 300);
        } else {
            set_transient('cardano_wallet_response_message', '❌ Wallet generation failed: ' . ($result['error'] ?? 'Unknown error'), 300);
            set_transient('cardano_wallet_response_type', 'error', 300);
        }

        // Clean buffer and use JavaScript redirect (more reliable in WordPress admin)
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=' . $redirect_tab) . '";</script>';
        exit;
    }

    // Clear test wallet
    if (isset($_POST['clear_test_wallet'])) {
        check_admin_referer('clear_test_wallet');
        delete_option('cardano_test_wallet');

        set_transient('cardano_wallet_response_message', '🗑️ Test wallet cleared! You can now generate a new one.', 300);
        set_transient('cardano_wallet_response_type', 'success', 300);

        // Clean buffer and use JavaScript redirect (more reliable in WordPress admin)
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=' . $redirect_tab) . '";</script>';
        exit;
    }

    // Clear transaction data from Live Panel
    if (isset($_POST['clear_tx_data'])) {
        check_admin_referer('clear_tx_data');
        delete_transient('cardano_tx_data_message');
        delete_transient('cardano_tx_data_type');
        delete_transient('cardano_tx_data_sections');

        // Redirect back to transactions tab
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=transactions') . '";</script>';
        exit;
    }

    // Save Tip Widget Settings
    if (isset($_POST['save_tip_widget'])) {
        check_admin_referer('save_tip_widget');

        $settings = array(
            'enabled' => isset($_POST['tip_widget_enabled']),
            'recipient_address' => sanitize_text_field($_POST['tip_recipient_address']),
            'network' => sanitize_text_field($_POST['tip_network']),
            'widget_title' => sanitize_text_field($_POST['tip_widget_title']),
            'widget_description' => sanitize_textarea_field($_POST['tip_widget_description']),
            'button_text' => sanitize_text_field($_POST['tip_button_text']),
            'preset_amounts' => array_map('floatval', array_filter(explode(',', $_POST['tip_preset_amounts']))),
            'allow_custom_amount' => isset($_POST['tip_allow_custom']),
            'min_amount' => floatval($_POST['tip_min_amount']),
            'allow_message' => isset($_POST['tip_allow_message']),
            'thank_you_message' => sanitize_textarea_field($_POST['tip_thank_you_message']),
            // Design settings
            'color_scheme' => sanitize_text_field($_POST['tip_color_scheme']),
            'widget_width' => sanitize_text_field($_POST['tip_widget_width']),
            'button_style' => sanitize_text_field($_POST['tip_button_style']),
            'widget_opacity' => floatval($_POST['tip_widget_opacity']),
        );

        update_option('cardano_tip_widget_settings', $settings);

        set_transient('cardano_wallet_response_message', '✅ Tip widget settings saved successfully!', 300);
        set_transient('cardano_wallet_response_type', 'success', 300);

        // Redirect back to tip widget tab
        if (ob_get_level()) ob_end_clean();
        echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=tip-widget') . '";</script>';
        exit;
    }

    // ========================================================================
    // END OF POST HANDLERS - NOW START PAGE RENDERING
    // ========================================================================

    // Load saved wallet at the top so it's available throughout the page
    $saved_wallet = get_option('cardano_test_wallet', null);

    // Get active tab
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'configuration';

    ?>
    <style>
        /* Add subtle breathing room to the entire plugin */
        .wrap {
            max-width: 1800px;
            margin: 10px auto !important;
            padding: 0 10px !important;
        }
        /* Add margin to all major content sections */
        .cardano-tab-content {
            padding: 15px;
            margin-bottom: 20px;
        }
        /* Ensure forms and boxes have good spacing */
        .wrap form {
            margin-bottom: 15px;
        }
        /* Add space around Live Data Panel */
        #live-tx-panel {
            margin: 15px 0 !important;
        }
    </style>

    <script>
        // Universal copy function that works in all contexts
        function copyToClipboard(button) {
            // Find the input or textarea element (previous sibling)
            var element = button.previousElementSibling;
            var text = element.tagName === 'TEXTAREA' ? element.textContent || element.value : element.value;

            // Try modern clipboard API first
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    showCopyFeedback(button, true);
                }).catch(function(err) {
                    // Fallback to legacy method
                    fallbackCopy(element, button);
                });
            } else {
                // Use legacy method
                fallbackCopy(element, button);
            }
        }

        function fallbackCopy(element, button) {
            try {
                element.select();
                element.setSelectionRange(0, 99999); // For mobile devices
                var successful = document.execCommand('copy');
                showCopyFeedback(button, successful);
            } catch (err) {
                showCopyFeedback(button, false);
            }
        }

        function showCopyFeedback(button, success) {
            var originalText = button.innerHTML;
            if (success) {
                button.innerHTML = '✅ Copied!';
                button.style.background = '#4caf50';
                button.style.color = 'white';
            } else {
                button.innerHTML = '❌ Failed';
                button.style.background = '#f44336';
                button.style.color = 'white';
            }
            setTimeout(function() {
                button.innerHTML = originalText;
                button.style.background = '';
                button.style.color = '';
            }, 2000);
        }
    </script>

    <div class="wrap">
        <div style="margin-left: 20px; margin-bottom: 25px;">
            <h1 style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 36px; margin: 0 0 8px 0; font-weight: 800;">
                PHP Cardano Utilities
            </h1>
            <p style="font-size: 16px; color: #6b7280; margin: 0 0 5px 0; font-weight: 500;">
                No dependencies, just pure PHP BABY! <span id="fire-emoji" style="cursor: pointer; display: inline-block; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">🔥</span>
            </p>
            <p style="font-size: 13px; color: #9ca3af; margin: 0; font-style: italic;">
                A plugin by <strong>Pb</strong>
            </p>
        </div>

        <!-- Ric Flair Easter Egg Modal -->
        <div id="ric-flair-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; justify-content: center; align-items: center;">
            <div style="position: relative; text-align: center;">
                <img src="<?php echo plugins_url('assets/rick-flair-woo.gif', __FILE__); ?>" style="max-width: 90%; max-height: 90vh; border-radius: 10px; box-shadow: 0 8px 32px rgba(0,0,0,0.4);">
                <button onclick="document.getElementById('ric-flair-modal').style.display='none'" style="position: absolute; top: -15px; right: -15px; background: #667eea; color: white; border: none; border-radius: 50%; width: 40px; height: 40px; font-size: 24px; cursor: pointer; box-shadow: 0 4px 8px rgba(0,0,0,0.3);">×</button>
            </div>
        </div>

        <script>
            document.getElementById('fire-emoji').addEventListener('click', function() {
                var modal = document.getElementById('ric-flair-modal');
                modal.style.display = 'flex';
            });

            // Close modal when clicking outside the image
            document.getElementById('ric-flair-modal').addEventListener('click', function(e) {
                if (e.target === this) {
                    this.style.display = 'none';
                }
            });
        </script>

        <!-- Custom Tab Navigation Styles -->
        <style>
            .cardano-nav-tabs {
                display: flex;
                gap: 12px;
                margin-bottom: 30px;
                margin-left: 20px;
                border-bottom: none;
            }
            .cardano-nav-tab {
                background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
                border: 2px solid #d1d5db;
                color: #374151;
                padding: 12px 24px;
                border-radius: 10px;
                text-decoration: none;
                font-weight: 600;
                font-size: 15px;
                transition: all 0.3s ease;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                position: relative;
                overflow: hidden;
            }
            .cardano-nav-tab:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.15);
                border-color: #9ca3af;
            }
            .cardano-nav-tab.active {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-color: #3730a3;
                color: white;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            }
            .cardano-nav-tab.active:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5);
            }
        </style>

        <!-- Tab Navigation -->
        <div class="cardano-nav-tabs">
            <a href="?page=cardano-wallet-test&tab=configuration" class="cardano-nav-tab <?php echo $active_tab == 'configuration' ? 'active' : ''; ?>">
                🔧 Configuration
            </a>
            <a href="?page=cardano-wallet-test&tab=transactions" class="cardano-nav-tab <?php echo $active_tab == 'transactions' ? 'active' : ''; ?>">
                💰 Transactions
            </a>
            <a href="?page=cardano-wallet-test&tab=tip-widget" class="cardano-nav-tab <?php echo $active_tab == 'tip-widget' ? 'active' : ''; ?>">
                💸 Tip Widget
            </a>
            <a href="?page=cardano-wallet-test&tab=tools" class="cardano-nav-tab <?php echo $active_tab == 'tools' ? 'active' : ''; ?>">
                🔬 Generation Diagnostics
            </a>
        </div>

        <!-- Global Disclaimer Modal (Available on all tabs) -->
        <div id="disclaimer-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999999; justify-content: center; align-items: center; overflow-y: auto; padding: 20px;">
            <div style="background: white; max-width: 800px; width: 100%; border-radius: 10px; box-shadow: 0 8px 32px rgba(0,0,0,0.4); position: relative; margin: auto;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 25px; border-radius: 10px 10px 0 0;">
                    <h2 style="margin: 0; color: white; font-size: 24px;">⚖️ Disclaimer & Legal Notice</h2>
                </div>
                <div style="padding: 30px; max-height: 60vh; overflow-y: auto;">
                    <h3 style="color: #374151; margin-top: 0;">Use at Your Own Risk</h3>
                    <p style="color: #6b7280; line-height: 1.8;">
                        This plugin is provided "AS IS" without warranty of any kind, either expressed or implied. The author(s) shall not be held liable for any damages, losses, or issues arising from the use of this software, including but not limited to:
                    </p>
                    <ul style="color: #6b7280; line-height: 1.8;">
                        <li>Loss of funds or cryptocurrency assets</li>
                        <li>Transaction failures or errors</li>
                        <li>Security breaches or compromised private keys</li>
                        <li>Data loss or corruption</li>
                        <li>Any other direct or indirect damages</li>
                    </ul>

                    <h3 style="color: #374151; margin-top: 25px;">Key Security Responsibilities</h3>
                    <p style="color: #6b7280; line-height: 1.8;">
                        <strong>You are solely responsible for:</strong>
                    </p>
                    <ul style="color: #6b7280; line-height: 1.8;">
                        <li><strong>Securing your private keys:</strong> Never share your private keys or mnemonic phrases. Store them securely offline.</li>
                        <li><strong>Server security:</strong> Ensure your WordPress server is properly secured with SSL, firewalls, and up-to-date software.</li>
                        <li><strong>Testing thoroughly:</strong> Always test on Preprod/testnet before using mainnet with real ADA.</li>
                        <li><strong>Understanding transactions:</strong> Know what transactions you're signing and submitting before execution.</li>
                        <li><strong>Backup procedures:</strong> Maintain secure backups of all critical wallet data.</li>
                    </ul>

                    <h3 style="color: #374151; margin-top: 25px;">Beta Software Notice</h3>
                    <p style="color: #6b7280; line-height: 1.8;">
                        This software is in <strong>beta</strong> and may contain bugs or unexpected behavior. Production use is at your own discretion and risk. Always verify transaction details before submission.
                    </p>

                    <h3 style="color: #374151; margin-top: 25px;">No Financial Advice</h3>
                    <p style="color: #6b7280; line-height: 1.8;">
                        Nothing in this plugin constitutes financial, legal, or tax advice. Consult with appropriate professionals before making financial decisions.
                    </p>

                    <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin-top: 25px; border-radius: 4px;">
                        <p style="margin: 0; color: #92400e; font-weight: 600;">
                            ⚠️ By using this plugin, you acknowledge that you have read, understood, and agree to these terms.
                        </p>
                    </div>
                </div>
                <div style="padding: 20px; background: #f9fafb; border-radius: 0 0 10px 10px; text-align: right;">
                    <button onclick="document.getElementById('disclaimer-modal').style.display='none'" style="background: #667eea; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        I Understand
                    </button>
                </div>
            </div>
        </div>

        <script>
            // Handle disclaimer links across all tabs
            document.addEventListener('DOMContentLoaded', function() {
                var disclaimerLinks = document.querySelectorAll('#show-disclaimer, .show-disclaimer-link');
                disclaimerLinks.forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        document.getElementById('disclaimer-modal').style.display = 'flex';
                    });
                });

                // Close modal when clicking outside
                var modal = document.getElementById('disclaimer-modal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === this) {
                            this.style.display = 'none';
                        }
                    });
                }
            });
        </script>

        <?php
        // Render the active tab content
        if ($active_tab == 'tools') {
            // Generation Diagnostics tab
            render_tools_tab();
            return;
        } elseif ($active_tab == 'configuration') {
            // Configuration tab
            render_configuration_tab($saved_wallet);
            return;
        } elseif ($active_tab == 'tip-widget') {
            // Tip Widget tab
            render_tip_widget_config();
            return;
        } elseif ($active_tab == 'transactions') {
            // Transactions tab - will render below
            // Continue to existing content
        } else {
            // Default to configuration if unknown tab
            render_configuration_tab($saved_wallet);
            return;
        }

        // If we reach here, we're rendering the Transactions tab
        ?>

        <?php
        // Test Send ADA (Build + Sign)
        if (isset($_POST['test_send_ada'])) {
            check_admin_referer('test_send_ada');

            require_once plugin_dir_path(__FILE__) . 'CardanoTransactionSignerPHP.php';
            require_once plugin_dir_path(__FILE__) . 'Ed25519Compat.php';

            $sender_address = $_POST['sender_address'] ?? '';
            $recipient_address = $_POST['recipient_address'] ?? '';
            $amount_ada = floatval($_POST['amount_ada'] ?? 1);
            $skey_hex = $_POST['skey_for_send'] ?? '';

            $network = $_POST['send_network'] ?? 'preprod';

            // Use saved API key based on network, or form override
            $default_key = $network === 'mainnet'
                ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
                : get_option('cardano_wallet_test_anvil_key_preprod', '');

            $anvil_api_key = !empty($_POST['anvil_api_key'])
                ? $_POST['anvil_api_key']
                : $default_key;

            // Start capturing ALL output for processing into Live Data Panel
            ob_start();
            $tx_start_time = microtime(true);

            // VALIDATION: Verify the sender address matches the private key
            if (!empty($skey_hex) && !empty($sender_address)) {
                echo '<p>🔍 Validating sender address matches private key...</p>';

                try {
                    // Derive public key from private key
                    if (strlen($skey_hex) === 128) {
                        // Extended key - use kL for public key derivation
                        $kL = hex2bin(substr($skey_hex, 0, 64));
                        $pub_key_bytes = Ed25519Compat::ge_scalarmult_base_noclamp($kL);
                    } else {
                        // Legacy key (64 chars) - would need different handling
                        // For now, we'll skip validation for legacy keys
                        echo '<p>⚠️ Using legacy 64-char key - address validation skipped</p>';
                        $pub_key_bytes = null;
                    }

                    if ($pub_key_bytes) {
                        $pub_key_hex = bin2hex($pub_key_bytes);
                        $key_hash = bin2hex(sodium_crypto_generichash($pub_key_bytes, '', 28)); // Blake2b-224

                        echo '<p><strong>Derived from your private key:</strong></p>';
                        echo '<ul style="font-family: monospace; font-size: 11px;">';
                        echo '<li>Public Key: ' . $pub_key_hex . '</li>';
                        echo '<li>Key Hash: ' . $key_hash . '</li>';
                        echo '</ul>';

                        // Extract key hash from sender address (Bech32 decode would be complex, so we'll just warn)
                        echo '<p><strong>Sender Address:</strong> <code>' . htmlspecialchars($sender_address) . '</code></p>';
                        echo '<p style="background: #fff3cd; padding: 10px; border-left: 3px solid #f59e0b;">⚠️ <strong>IMPORTANT:</strong> If the transaction fails with "MissingVKeyWitnessesUTXOW", the sender address doesn\'t match your private key. Clear the saved wallet and generate a new one.</p>';
                    }
                } catch (\Exception $e) {
                    echo '<p style="background: #fee; padding: 10px; border-left: 3px solid #f00;">❌ Validation error: ' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }

            if (empty($anvil_api_key)) {
                echo '<div class="notice notice-error"><p>❌ No API key provided. Save one below or enter in the form.</p></div>';
            } else {
                // Step 1: Build transaction with Anvil API
                $api_url = $network === 'mainnet'
                    ? 'https://prod.api.ada-anvil.app/v2/services'
                    : 'https://preprod.api.ada-anvil.app/v2/services';

                $build_request = [
                    'changeAddress' => $sender_address,
                    'outputs' => [
                        [
                            'address' => $recipient_address,
                            'lovelace' => intval($amount_ada * 1000000)
                        ]
                    ]
                ];

                echo '<p>📡 Calling Anvil API to build transaction...</p>';
                echo '<p><small>Endpoint: <code>' . $api_url . '/transactions/build</code></small></p>';

                $response = wp_remote_post($api_url . '/transactions/build', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Api-Key' => $anvil_api_key
                    ],
                    'body' => json_encode($build_request),
                    'timeout' => 30
                ]);

            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p>❌ Anvil API Error: ' . $response->get_error_message() . '</p></div>';
            } else {
                $body = wp_remote_retrieve_body($response);
                $status_code = wp_remote_retrieve_response_code($response);
                $build_result = json_decode($body, true);

                echo '<p><strong>Response Status:</strong> ' . $status_code . '</p>';

                if ($status_code !== 200) {
                    echo '<div class="notice notice-error"><p>❌ Anvil API Error (Status ' . $status_code . ')</p></div>';
                    echo '<p><strong>Error Message:</strong> ' . htmlspecialchars($build_result['message'] ?? 'Unknown error') . '</p>';
                    echo '<details><summary>Full Response</summary><pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; font-size: 11px;">';
                    echo htmlspecialchars(print_r($build_result, true));
                    echo '</pre></details>';
                } else {
                    echo '<p>✅ Transaction built successfully!</p>';

                    // Display full API response
                    echo '<details><summary>📋 Full Anvil Response</summary><pre style="background: #e8f5e9; padding: 10px; border: 1px solid #4caf50; overflow-x: auto; font-size: 11px;">';
                    echo htmlspecialchars(json_encode($build_result, JSON_PRETTY_PRINT));
                    echo '</pre></details>';

                    // Step 2: Sign with pure PHP
                    // Anvil returns the unsigned transaction - try 'stripped' first (no CBOR tags), then 'complete'
                    $tx_hex = $build_result['stripped'] ?? $build_result['complete'] ?? $build_result['transaction'] ?? '';

                    if (empty($tx_hex)) {
                        echo '<div class="notice notice-error"><p>❌ No transaction hex returned from Anvil</p></div>';
                        echo '<p><strong>Available keys in response:</strong> ' . implode(', ', array_keys($build_result)) . '</p>';
                    } else {
                        $field_used = isset($build_result['stripped']) ? 'stripped' : (isset($build_result['complete']) ? 'complete' : 'transaction');
                        echo '<p><strong>Using field:</strong> ' . $field_used . '</p>';
                        echo '<p><strong>TX Hex length:</strong> ' . strlen($tx_hex) . ' chars (' . (strlen($tx_hex) / 2) . ' bytes)</p>';
                        echo '<p><strong>Private key length:</strong> ' . strlen($skey_hex) . ' chars</p>';

                        echo '<p>🔏 Signing transaction with pure PHP...</p>';
                        flush();  // Force output to browser

                        $start_time = microtime(true);

                        try {
                            $sign_result = CardanoTransactionSignerPHP::signTransaction($tx_hex, $skey_hex);
                            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
                            echo '<p>✅ Signing completed in ' . $elapsed . 'ms</p>';
                        } catch (\Throwable $e) {
                            $elapsed = round((microtime(true) - $start_time) * 1000, 2);
                            echo '<div class="notice notice-error"><p>❌ Signing threw exception after ' . $elapsed . 'ms: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
                            echo '<details><summary>Stack Trace</summary><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></details>';
                            $sign_result = ['success' => false, 'error' => $e->getMessage()];
                        }

                        if ($sign_result['success']) {
                            echo '<div class="notice notice-success"><p>🎉 Transaction signed successfully in ' . $elapsed . 'ms!</p></div>';

                            // Show debug log
                            if (!empty($sign_result['debug'])) {
                                echo '<details open><summary>🔍 Signing Process Debug Log</summary>';
                                echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; font-size: 11px;">';
                                foreach ($sign_result['debug'] as $log_line) {
                                    echo htmlspecialchars($log_line) . "\n";
                                }
                                echo '</pre></details>';
                            }

                            echo '<div style="background: #e8f5e9; padding: 20px; border: 2px solid #4caf50; margin: 20px 0;">';
                            echo '<h3 style="margin-top: 0;">📋 Ready to Submit! Copy these 3 values:</h3>';

                            echo '<h4>1️⃣ Unsigned Transaction (from Anvil):</h4>';
                            echo '<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; word-wrap: break-word; font-size: 10px; margin: 10px 0;">';
                            echo htmlspecialchars($tx_hex);
                            echo '</pre>';

                            echo '<h4>2️⃣ Your Witness Set (from PHP signer):</h4>';
                            echo '<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; word-wrap: break-word; font-size: 10px; margin: 10px 0;">';
                            echo htmlspecialchars($sign_result['witnessSetHex']);
                            echo '</pre>';

                            echo '<h4>3️⃣ Anvil Witness Set (from build response):</h4>';
                            echo '<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; word-wrap: break-word; font-size: 10px; margin: 10px 0;">';
                            echo htmlspecialchars($build_result['witnessSet'] ?? 'N/A');
                            echo '</pre>';

                            echo '<p style="margin-bottom: 0;"><strong>✅ Copy these 3 values to the submission form below!</strong></p>';
                            echo '</div>';

                            echo '<details><summary>📊 Complete Signed Transaction (for reference/debugging)</summary>';
                            echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; word-wrap: break-word; font-size: 10px;">';
                            echo htmlspecialchars($sign_result['signedTx']);
                            echo '</pre></details>';
                        } else {
                            echo '<div class="notice notice-error"><p>❌ Signing Error: ' . htmlspecialchars($sign_result['error']) . '</p></div>';

                            // Show debug log on error too
                            if (!empty($sign_result['debug'])) {
                                echo '<details open><summary>🔍 Debug Log (Error)</summary>';
                                echo '<pre style="background: #ffebee; padding: 10px; border: 1px solid #f44336; overflow-x: auto; font-size: 11px;">';
                                foreach ($sign_result['debug'] as $log_line) {
                                    echo htmlspecialchars($log_line) . "\n";
                                }
                                echo '</pre></details>';
                            }
                        }
                    }
                }
            }
            } // End API key check

            // Capture all the output and process into Live Data Panel sections
            $raw_output = ob_get_clean();
            $tx_elapsed = round((microtime(true) - $tx_start_time) * 1000, 2);

            // Store in transients for Live Data Panel display
            set_transient('cardano_tx_data_message', '✅ Transaction signed successfully in ' . $tx_elapsed . 'ms', 300);
            set_transient('cardano_tx_data_type', 'success', 300);
            set_transient('cardano_tx_data_sections', [
                [
                    'title' => '🎉 Complete Output',
                    'content' => $raw_output // Raw HTML output from the process
                ]
            ], 300);

            // Redirect to show in Live Data Panel
            if (ob_get_level()) ob_end_clean();
            echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=transactions') . '";</script>';
            exit;
        }

        // Submit Signed Transaction
        if (isset($_POST['submit_transaction'])) {
            check_admin_referer('submit_transaction');

            $unsigned_tx_hex = $_POST['unsigned_tx_hex'] ?? '';
            $witness_set_hex = $_POST['witness_set_hex'] ?? '';
            $anvil_witness_set_hex = $_POST['anvil_witness_set_hex'] ?? '';
            $network = $_POST['submit_network'] ?? 'preprod';

            // Use saved API key based on network
            $anvil_api_key = $network === 'mainnet'
                ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
                : get_option('cardano_wallet_test_anvil_key_preprod', '');

            // Start capturing output for Live Data Panel
            ob_start();
            $submit_start_time = microtime(true);

            echo '<h2>Submitting Transaction to Blockchain...</h2>';

            if (empty($anvil_api_key)) {
                echo '<div class="notice notice-error"><p>❌ No ' . $network . ' API key saved</p></div>';
            } elseif (empty($unsigned_tx_hex)) {
                echo '<div class="notice notice-error"><p>❌ No unsigned transaction provided</p></div>';
            } else {
                $api_url = $network === 'mainnet'
                    ? 'https://prod.api.ada-anvil.app/v2/services'
                    : 'https://preprod.api.ada-anvil.app/v2/services';

                echo '<p>📤 Submitting to: <code>' . $api_url . '/transactions/submit</code></p>';
                echo '<p><strong>Network:</strong> ' . $network . '</p>';

                // Build signatures array - YOUR witness MUST come first!
                $signatures = [];
                if (!empty($witness_set_hex)) {
                    $signatures[] = $witness_set_hex;
                }
                if (!empty($anvil_witness_set_hex)) {
                    $signatures[] = $anvil_witness_set_hex;
                }

                // Anvil expects: { "transaction": "unsigned_tx", "signatures": ["witness1", "witness2"] }
                $submit_request = [
                    'transaction' => $unsigned_tx_hex,
                    'signatures' => $signatures
                ];

                // Debug: show what we're sending
                echo '<details><summary>📤 Submit Request Payload</summary><pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; font-size: 11px;">';
                echo htmlspecialchars(json_encode($submit_request, JSON_PRETTY_PRINT));
                echo '</pre></details>';

                $response = wp_remote_post($api_url . '/transactions/submit', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Api-Key' => $anvil_api_key
                    ],
                    'body' => json_encode($submit_request),
                    'timeout' => 30
                ]);

                if (is_wp_error($response)) {
                    echo '<div class="notice notice-error"><p>❌ Connection Error: ' . $response->get_error_message() . '</p></div>';
                } else {
                    $status_code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    $result = json_decode($body, true);

                    echo '<p><strong>Response Status:</strong> ' . $status_code . '</p>';

                    if ($status_code === 200 || $status_code === 202) {
                        echo '<div class="notice notice-success"><p>🎉 Transaction submitted successfully!</p></div>';

                        if (isset($result['hash']) || isset($result['txHash']) || isset($result['transactionId'])) {
                            $tx_hash = $result['hash'] ?? $result['txHash'] ?? $result['transactionId'];
                            echo '<h3>Transaction Hash:</h3>';
                            echo '<p style="background: #d4edda; padding: 15px; border: 1px solid #28a745; font-family: monospace; word-break: break-all;">';
                            echo htmlspecialchars($tx_hash);
                            echo '</p>';

                            $explorer_url = $network === 'mainnet'
                                ? 'https://cardanoscan.io/transaction/' . $tx_hash
                                : 'https://preprod.cardanoscan.io/transaction/' . $tx_hash;

                            echo '<p>🔍 <a href="' . $explorer_url . '" target="_blank">View on Cardano Explorer</a></p>';
                        }

                        echo '<details><summary>📋 Full Response</summary><pre style="background: #e8f5e9; padding: 10px; border: 1px solid #4caf50; overflow-x: auto; font-size: 11px;">';
                        echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT));
                        echo '</pre></details>';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Submission Failed (Status ' . $status_code . ')</p></div>';
                        echo '<p><strong>Error:</strong> ' . htmlspecialchars($result['message'] ?? 'Unknown error') . '</p>';
                        echo '<details><summary>Full Response</summary><pre style="background: #ffebee; padding: 10px; border: 1px solid #f44336; overflow-x: auto; font-size: 11px;">';
                        echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT));
                        echo '</pre></details>';
                    }
                }
            }

            // Capture submit output and APPEND to existing transaction data
            $submit_output = ob_get_clean();
            $submit_elapsed = round((microtime(true) - $submit_start_time) * 1000, 2);

            // Check if we got a TX hash (indicates success)
            $tx_hash = null;
            if (isset($result) && isset($result['txHash'])) {
                $tx_hash = $result['txHash'];
            }

            // Get existing sections (if any from build/sign)
            $existing_sections = get_transient('cardano_tx_data_sections') ?: [];

            // Add submit output as new section with success indicator
            $section_title = $tx_hash
                ? '✅ Blockchain Submission SUCCESS (' . $submit_elapsed . 'ms)'
                : '📤 Blockchain Submission (' . $submit_elapsed . 'ms)';

            $existing_sections[] = [
                'title' => $section_title,
                'content' => $submit_output
            ];

            // Update transients with combined data
            $success_message = $tx_hash
                ? '🎉 Transaction submitted successfully! TX Hash: ' . substr($tx_hash, 0, 16) . '...'
                : '📤 Transaction submission completed';

            set_transient('cardano_tx_data_message', $success_message, 300);
            set_transient('cardano_tx_data_type', 'success', 300);
            set_transient('cardano_tx_data_sections', $existing_sections, 300);

            // Redirect to show updated Live Data Panel
            if (ob_get_level()) ob_end_clean();
            echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=transactions') . '";</script>';
            exit;
        }

        // Test transaction signing
        if (isset($_POST['test_signing'])) {
            check_admin_referer('test_signing');

            require_once plugin_dir_path(__FILE__) . 'CardanoTransactionSignerPHP.php';

            $tx_hex = $_POST['tx_hex'] ?? '';
            $skey_hex = $_POST['skey_hex'] ?? '';

            // Start capturing output for Live Data Panel
            ob_start();
            $sign_start_time = microtime(true);

            echo '<h2>Testing Transaction Signing...</h2>';

            $result = CardanoTransactionSignerPHP::signTransaction($tx_hex, $skey_hex);
            $sign_elapsed = round((microtime(true) - $sign_start_time) * 1000, 2);

            if ($result['success']) {
                echo '<div style="padding: 15px; background: #e8f5e9; border: 1px solid #4caf50; border-radius: 5px; margin: 15px 0;">';
                echo '<p style="margin: 0;"><strong>✅ Transaction signed successfully in ' . $sign_elapsed . 'ms</strong></p>';
                echo '</div>';

                echo '<h3>Signed Transaction (CBOR Hex):</h3>';
                echo '<div style="position: relative;">';
                echo '<textarea readonly style="width: 100%; font-family: monospace; font-size: 11px; background: #f5f5f5; padding: 15px; border: 1px solid #ddd; word-wrap: break-word; height: 120px;">';
                echo htmlspecialchars($result['signedTx']);
                echo '</textarea>';
                echo '<button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy Signed TX</button>';
                echo '</div>';

                // Show TX hash if available
                if (isset($result['txHash'])) {
                    echo '<p><strong>Transaction Hash:</strong> <code>' . htmlspecialchars($result['txHash']) . '</code></p>';
                }
            } else {
                echo '<div style="padding: 15px; background: #ffebee; border: 1px solid #f44336; border-radius: 5px; margin: 15px 0;">';
                echo '<p style="margin: 0;"><strong>❌ Error:</strong> ' . htmlspecialchars($result['error']) . '</p>';
                echo '</div>';
            }

            // Capture all output and store in Live Data Panel
            $sign_output = ob_get_clean();

            $section_title = $result['success']
                ? '✅ Transaction Signed (' . $sign_elapsed . 'ms)'
                : '❌ Signing Failed (' . $sign_elapsed . 'ms)';

            $success_message = $result['success']
                ? '✅ Transaction signed successfully!'
                : '❌ Transaction signing failed';

            set_transient('cardano_tx_data_message', $success_message, 300);
            set_transient('cardano_tx_data_type', $result['success'] ? 'success' : 'error', 300);
            set_transient('cardano_tx_data_sections', [
                [
                    'title' => $section_title,
                    'content' => $sign_output
                ]
            ], 300);

            // Redirect to show in Live Data Panel
            if (ob_get_level()) ob_end_clean();
            echo '<script>window.location.href = "' . admin_url('admin.php?page=cardano-wallet-test&tab=transactions') . '";</script>';
            exit;
        }

        // Test wallet generation (temporary - not saved)
        if (isset($_POST['generate_wallet'])) {
            check_admin_referer('generate_test_wallet');

            require_once plugin_dir_path(__FILE__) . 'CardanoWalletPHP.php';

            $network = $_POST['network'] ?? 'preprod';

            echo '<h2>Generating Temporary Wallet...</h2>';

            $start_time = microtime(true);
            $result = CardanoWalletPHP::generateWallet($network);
            $elapsed = round((microtime(true) - $start_time) * 1000, 2);

            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✅ Wallet generated successfully in ' . $elapsed . 'ms</p></div>';

                // Display mnemonic prominently
                echo '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 5px;">';
                echo '<h3 style="margin-top: 0;">🔑 Recovery Phrase (24 Words)</h3>';
                echo '<p style="color: #856404;"><strong>⚠️ WARNING:</strong> Save these words in order. Anyone with this phrase can control your wallet!</p>';
                echo '<div style="background: white; padding: 15px; border: 1px solid #ffc107; border-radius: 3px; font-family: monospace; font-size: 14px; word-spacing: 8px; line-height: 2;">';
                echo htmlspecialchars($result['mnemonic']);
                echo '</div></div>';

                // Display addresses
                echo '<div style="background: #e8f5e9; border: 2px solid #4caf50; padding: 20px; margin: 20px 0; border-radius: 5px;">';
                echo '<h3 style="margin-top: 0;">📬 Addresses</h3>';
                echo '<p><strong>Payment Address:</strong><br>';
                echo '<code style="background: white; padding: 10px; display: block; word-break: break-all; font-size: 12px; border: 1px solid #4caf50;">';
                echo htmlspecialchars($result['addresses']['payment_address']);
                echo '</code></p>';
                echo '<p><strong>Stake Address:</strong><br>';
                echo '<code style="background: white; padding: 10px; display: block; word-break: break-all; font-size: 12px; border: 1px solid #4caf50;">';
                echo htmlspecialchars($result['addresses']['stake_address']);
                echo '</code></p>';
                echo '</div>';

                // Display keys
                echo '<div style="background: #ffebee; border: 2px solid #f44336; padding: 20px; margin: 20px 0; border-radius: 5px;">';
                echo '<h3 style="margin-top: 0;">🔐 Keys (Keep Secret!)</h3>';
                echo '<p><strong>Payment Private Key (Hex):</strong><br>';
                echo '<code style="background: white; padding: 10px; display: block; word-break: break-all; font-size: 11px; border: 1px solid #f44336;">';
                echo htmlspecialchars($result['payment_skey_hex']);
                echo '</code></p>';
                echo '<p><strong>Payment Key Hash:</strong><br>';
                echo '<code style="background: white; padding: 10px; display: block; word-break: break-all; font-size: 11px; border: 1px solid #f44336;">';
                echo htmlspecialchars($result['payment_keyhash']);
                echo '</code></p>';
                echo '</div>';

                echo '<details><summary>📋 Full Wallet Data (JSON)</summary>';
                echo '<pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto; font-size: 11px;">';
                echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT));
                echo '</pre></details>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Error: ' . htmlspecialchars($result['error']) . '</p></div>';
            }
        }
        ?>

        <!-- Prerequisite Check Banner -->
        <?php
        $mainnet_api_key = get_option('cardano_wallet_test_anvil_key_mainnet', '');
        $preprod_api_key = get_option('cardano_wallet_test_anvil_key_preprod', '');
        $has_api_keys = !empty($mainnet_api_key) || !empty($preprod_api_key);
        $has_wallet = !empty($saved_wallet);
        $all_ready = $has_api_keys && $has_wallet;
        ?>

        <div style="background: <?php echo $all_ready ? '#e8f5e9' : '#fff3cd'; ?>;
                    border: 2px solid <?php echo $all_ready ? '#4caf50' : '#f59e0b'; ?>;
                    padding: 20px;
                    margin-bottom: 30px;
                    border-radius: 8px;">
            <h3 style="margin-top: 0;">📋 Prerequisites</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div>
                    <?php if ($has_api_keys): ?>
                        <strong>✅ API Keys:</strong> Configured
                        <?php if (!empty($preprod_api_key)): ?>(Preprod)<?php endif; ?>
                        <?php if (!empty($mainnet_api_key)): ?>(Mainnet)<?php endif; ?>
                    <?php else: ?>
                        <strong>⚠️ API Keys:</strong> Not configured<br>
                        <a href="?page=cardano-wallet-test&tab=configuration" class="button button-small" style="margin-top: 5px;">→ Go to Configuration</a>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($has_wallet): ?>
                        <strong>✅ Test Wallet:</strong> Saved (<?php echo esc_html($saved_wallet['network'] ?? 'preprod'); ?>)
                    <?php else: ?>
                        <strong>⚠️ Test Wallet:</strong> Not generated<br>
                        <a href="?page=cardano-wallet-test&tab=configuration" class="button button-small" style="margin-top: 5px;">→ Go to Configuration</a>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($all_ready): ?>
                        <strong style="color: #2e7d32; font-size: 18px;">✅ Ready to transact!</strong>
                    <?php else: ?>
                        <strong style="color: #f57c00;">⚠️ Complete setup first</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Live Transaction Data Panel -->
        <?php
        $tx_data_message = get_transient('cardano_tx_data_message');
        $tx_data_type = get_transient('cardano_tx_data_type');
        $tx_data_sections = get_transient('cardano_tx_data_sections');

        if ($tx_data_message && $tx_data_sections):
        ?>
        <div id="live-tx-panel" style="background: <?php echo $tx_data_type == 'success' ? '#e8f5e9' : '#ffebee'; ?>;
                    border: 2px solid <?php echo $tx_data_type == 'success' ? '#4caf50' : '#f44336'; ?>;
                    padding: 25px;
                    margin-bottom: 30px;
                    border-radius: 8px;
                    animation: slideDown 0.3s ease-out;
                    max-height: 80vh;
                    overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">📊 Live Transaction Data</h2>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="clear_tx_data" value="1">
                    <?php wp_nonce_field('clear_tx_data'); ?>
                    <button type="submit" class="button" style="background: #f44336; color: white; border-color: #d32f2f;">
                        🗑️ Clear
                    </button>
                </form>
            </div>

            <!-- Success/Error Message -->
            <div style="padding: 15px; background: white; border-radius: 5px; margin-bottom: 20px;">
                <strong style="font-size: 16px; color: <?php echo $tx_data_type == 'success' ? '#2e7d32' : '#c62828'; ?>;">
                    <?php echo wp_kses_post($tx_data_message); ?>
                </strong>
            </div>

            <!-- All Data Sections (ALL OPEN by default) -->
            <?php foreach ($tx_data_sections as $section): ?>
            <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 5px; border-left: 4px solid #2196f3;">
                <h3 style="margin-top: 0; color: #1976d2;"><?php echo esc_html($section['title']); ?></h3>
                <div style="font-family: monospace; font-size: 12px; line-height: 1.6;">
                    <?php echo $section['content']; // Already escaped in handler ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <style>
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
        <?php endif; ?>

        <!-- Introduction Section -->
        <div style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196f3; padding: 30px; border-radius: 10px; margin-bottom: 30px;">
            <h2 style="margin-top: 0; color: #0d47a1; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 32px;">🚀</span>
                Build & Submit Cardano Transactions
            </h2>
            <p style="font-size: 15px; line-height: 1.8; color: #1565c0; margin: 0 0 20px 0;">
                <strong>Building transactions and submitting them to the blockchain is easy with Cardano + Anvil + WordPress!</strong> Here you'll use the credentials from the wallet you just created to submit real transactions. All you have to do is make sure your Preprod address is funded (it can take a few minutes on Preprod) and enter the recipient address.
            </p>

            <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; border-left: 4px solid #0d47a1; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0; color: #0d47a1; font-size: 16px;">📋 The Workflow:</h3>
                <ol style="margin: 10px 0; padding-left: 25px; line-height: 2; color: #1565c0;">
                    <li><strong>Build & Sign:</strong> The Anvil API builds the transaction for the amount you want to send, then we automatically sign it with your private key when we get the response back from Anvil!</li>
                    <li><strong>Review:</strong> Check the transaction details, witness set, and transaction hash in the output.</li>
                    <li><strong>Submit:</strong> Copy the unsigned transaction and witness set into the submission form to broadcast to the blockchain.</li>
                    <li><strong>Verify:</strong> Get your transaction ID (TxHash) to track on a Cardano explorer!</li>
                </ol>
            </div>

            <div style="background: rgba(255,255,255,0.6); padding: 15px; border-radius: 8px; margin-top: 20px; border-left: 4px solid #0d47a1; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <p style="margin: 0; color: #1565c0; line-height: 1.6;">
                    <strong>💡 Pro Tip:</strong> Start with Preprod testnet to practice! You can get free test ADA from the <a href="https://docs.cardano.org/cardano-testnets/tools/faucet/" target="_blank" style="color: #0d47a1; text-decoration: underline;">Cardano Faucet</a>. Once you're comfortable, switch to Mainnet for real transactions.
                </p>
            </div>
        </div>

        <hr style="margin: 40px 0; border: none; border-top: 2px solid #e0e0e0;">

        <!-- Tooltip Styles for Transaction Tab -->
        <style>
            .tooltip-container {
                position: relative;
                display: inline-block;
                margin-left: 5px;
            }
            .tooltip-icon {
                display: inline-block;
                width: 18px;
                height: 18px;
                line-height: 18px;
                text-align: center;
                background: #2271b1;
                color: white;
                border-radius: 50%;
                font-size: 12px;
                font-weight: bold;
                cursor: help;
                vertical-align: middle;
            }
            .tooltip-text {
                visibility: hidden;
                width: 320px;
                background-color: #2c3e50;
                color: #fff;
                text-align: left;
                border-radius: 6px;
                padding: 12px;
                position: absolute;
                z-index: 1000;
                bottom: 125%;
                left: 50%;
                margin-left: -160px;
                opacity: 0;
                transition: opacity 0.3s;
                font-size: 13px;
                line-height: 1.6;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            }
            .tooltip-text::after {
                content: "";
                position: absolute;
                top: 100%;
                left: 50%;
                margin-left: -5px;
                border-width: 5px;
                border-style: solid;
                border-color: #2c3e50 transparent transparent transparent;
            }
            .tooltip-container:hover .tooltip-text {
                visibility: visible;
                opacity: 1;
            }
        </style>

        <!-- Build & Sign Transaction Section -->
        <div style="background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 2px solid #4caf50; padding: 25px; border-radius: 10px; margin-bottom: 30px;">
            <h2 style="margin: 0 0 20px 0; color: #2e7d32; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 28px;">🔨</span>
                Build & Sign Transaction
                <span class="tooltip-container">
                    <span class="tooltip-icon">?</span>
                    <span class="tooltip-text">
                        This calls the Anvil API to build your transaction, then signs it locally with pure PHP. No submission happens here - just building and signing for testing!
                    </span>
                </span>
            </h2>

        <form method="post">
            <?php wp_nonce_field('test_send_ada'); ?>

            <table class="form-table">
                <?php if (empty($mainnet_api_key) && empty($preprod_api_key)): ?>
                <tr>
                    <th><label for="anvil_api_key">Anvil API Key</label></th>
                    <td>
                        <input type="text" name="anvil_api_key" id="anvil_api_key" style="width: 100%; font-family: monospace;" placeholder="Your Anvil API key" required>
                        <p class="description">Save your API keys above to avoid re-entering them each time</p>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <th>Anvil API Keys</th>
                    <td>
                        <?php if (!empty($mainnet_api_key)): ?>
                        <p>🔴 <strong>Mainnet:</strong> <code><?php echo esc_html(substr($mainnet_api_key, 0, 20) . '...'); ?></code></p>
                        <?php endif; ?>
                        <?php if (!empty($preprod_api_key)): ?>
                        <p>🟢 <strong>Preprod:</strong> <code><?php echo esc_html(substr($preprod_api_key, 0, 20) . '...'); ?></code></p>
                        <?php endif; ?>
                        <p class="description">Saved API keys will be used automatically based on selected network</p>
                        <input type="hidden" name="anvil_api_key" value="">
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><label for="send_network">Network</label></th>
                    <td>
                        <select name="send_network" id="send_network">
                            <option value="preprod">Preprod (Testnet)</option>
                            <option value="mainnet">Mainnet</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="sender_address">Sender Address</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                The payment address that will send the ADA. This address must have sufficient funds to cover the amount plus transaction fees (~0.17 ADA).
                            </span>
                        </span>
                    </th>
                    <td>
                        <input type="text" name="sender_address" id="sender_address" value="<?php echo $saved_wallet ? esc_attr($saved_wallet['payment_address']) : ''; ?>" style="width: 100%; font-family: monospace; font-size: 11px; <?php echo $saved_wallet ? 'background: #e8f5e9; border: 2px solid #4caf50;' : ''; ?>" placeholder="addr_test1... or addr1...">
                        <p class="description"><?php echo $saved_wallet ? '✅ Auto-filled from saved test wallet' : 'Payment address from generated wallet (must have funds!)'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="recipient_address">Recipient Address</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                The destination address where ADA will be sent. Can be any valid Cardano payment address on the selected network.
                            </span>
                        </span>
                    </th>
                    <td>
                        <input type="text" name="recipient_address" id="recipient_address" style="width: 100%; font-family: monospace; font-size: 11px;" placeholder="addr_test1... or addr1...">
                        <p class="description">Where to send the ADA</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="amount_ada">Amount (ADA)</label></th>
                    <td>
                        <input type="number" name="amount_ada" id="amount_ada" step="0.01" value="1" min="0.01" style="width: 200px;">
                        <p class="description">Amount to send (minimum 0.01 ADA, must cover fees ~0.17 ADA)</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="skey_for_send">Sender Private Key (Extended)</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                <strong>🔐 This is what signs your transaction!</strong> The full 128-character extended private key (kL||kR) is required for proper CIP-1852 compliant signing using no-clamp Ed25519.
                            </span>
                        </span>
                    </th>
                    <td>
                        <input type="text" name="skey_for_send" id="skey_for_send" value="<?php echo $saved_wallet ? esc_attr($saved_wallet['payment_skey_extended']) : ''; ?>" style="width: 100%; font-family: monospace; font-size: 11px; <?php echo $saved_wallet ? 'background: #fff3cd; border: 2px solid #f59e0b;' : ''; ?>" placeholder="128-character extended private key hex (kL||kR)">
                        <p class="description">
                            <?php
                            if ($saved_wallet) {
                                echo '🔐 Auto-filled from saved wallet (128 chars - full extended key)<br>';
                                echo '✅ This is the FULL extended key required for proper CIP-1852 signing';
                            } else {
                                echo 'Use the 128-character extended key (kL||kR) from your wallet - required for no-clamp Ed25519 signing';
                            }
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit" style="margin: 20px 0 0 0; padding: 0;">
                <button type="submit" name="test_send_ada" class="button button-primary" style="background: #4caf50; border-color: #388e3c; font-size: 15px; padding: 8px 20px; height: auto;">
                    🔨 Build & Sign Transaction
                </button>
            </p>
        </form>
        </div>

        <!-- Submit Transaction Section -->
        <div style="background: linear-gradient(135deg, #fff8e1 0%, #ffe082 100%); border: 2px solid #ff9800; padding: 25px; border-radius: 10px; margin-bottom: 30px;">
            <h2 style="margin: 0 0 20px 0; color: #e65100; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 28px;">📤</span>
                Submit Transaction to Blockchain
                <span class="tooltip-container">
                    <span class="tooltip-icon" style="background: #ff9800;">⚠️</span>
                    <span class="tooltip-text">
                        <strong>⚠️ CAUTION:</strong> This will ACTUALLY SUBMIT your transaction to the blockchain! Make sure you've tested everything and are ready to broadcast. This action cannot be undone!
                    </span>
                </span>
            </h2>

        <form method="post">
            <?php wp_nonce_field('submit_transaction'); ?>

            <table class="form-table">
                <tr>
                    <th>
                        <label for="unsigned_tx_hex">Unsigned Transaction</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                The raw unsigned transaction hex returned by Anvil API. This is the transaction body before any signatures are added.
                            </span>
                        </span>
                    </th>
                    <td>
                        <textarea name="unsigned_tx_hex" id="unsigned_tx_hex" rows="3" style="width: 100%; font-family: monospace; font-size: 11px; background: #f0f9ff; border: 2px solid #0ea5e9;" placeholder="Paste the unsigned transaction hex from Anvil"></textarea>
                        <p class="description">📋 Copy the "Unsigned Transaction (from Anvil)" hex from the build result above</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="witness_set_hex">Your Witness Set</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                Your cryptographic signature (witness) created by signing the transaction with your private key. This proves you authorize the transaction.
                            </span>
                        </span>
                    </th>
                    <td>
                        <textarea name="witness_set_hex" id="witness_set_hex" rows="2" style="width: 100%; font-family: monospace; font-size: 11px; background: #f0fdf4; border: 2px solid #4caf50;" placeholder="Paste your witness set hex"></textarea>
                        <p class="description">📋 Copy the "Your Witness Set" hex from the signing result above</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="anvil_witness_set_hex">Anvil Witness Set (Optional)</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                Some transactions may include additional witnesses from Anvil (e.g., for script validation). If Anvil returned a "witnessSet" field, paste it here. Otherwise, leave blank.
                            </span>
                        </span>
                    </th>
                    <td>
                        <textarea name="anvil_witness_set_hex" id="anvil_witness_set_hex" rows="2" style="width: 100%; font-family: monospace; font-size: 11px; background: #fef3c7; border: 2px dashed #f59e0b;" placeholder="Paste Anvil's witnessSet if there is one (optional)"></textarea>
                        <p class="description">📋 Optional: If Anvil returned a "witnessSet" field, paste it here</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="submit_network">Network</label></th>
                    <td>
                        <select name="submit_network" id="submit_network">
                            <option value="preprod" selected>Preprod (Testnet)</option>
                            <option value="mainnet">⚠️ Mainnet (REAL ADA!)</option>
                        </select>
                        <p class="description">Select the network where you built the transaction</p>
                    </td>
                </tr>
            </table>

            <p class="submit" style="margin: 20px 0 0 0; padding: 0;">
                <button type="submit" name="submit_transaction" class="button button-secondary" style="background: #ff9800; color: white; border-color: #f57c00; font-size: 15px; padding: 8px 20px; height: auto; font-weight: 600;" onclick="return confirm('Are you sure you want to submit this transaction to the blockchain? This cannot be undone!');">
                    📤 Submit Transaction to Blockchain
                </button>
            </p>
        </form>
        </div>

        <!-- Test Transaction Signing Section -->
        <div style="background: linear-gradient(135deg, #f3e5f5 0%, #ce93d8 100%); border: 2px solid #9c27b0; padding: 25px; border-radius: 10px; margin-bottom: 30px;">
            <h2 style="margin: 0 0 20px 0; color: #6a1b9a; display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 28px;">✍️</span>
                Test Transaction Signing
                <span class="tooltip-container">
                    <span class="tooltip-icon">?</span>
                    <span class="tooltip-text">
                        Sign any unsigned transaction CBOR hex with your private key. Perfect for testing your signing implementation or working with transactions from external sources.
                    </span>
                </span>
            </h2>

        <form method="post">
            <?php wp_nonce_field('test_signing'); ?>

            <table class="form-table">
                <tr>
                    <th>
                        <label for="tx_hex">Transaction Hex (CBOR)</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                Any unsigned Cardano transaction in CBOR hex format. Can come from Anvil, your own transaction builder, or any other source.
                            </span>
                        </span>
                    </th>
                    <td>
                        <textarea name="tx_hex" id="tx_hex" rows="3" style="width: 100%; font-family: monospace; font-size: 11px; background: #f0f9ff; border: 2px solid #9c27b0;" placeholder="Paste unsigned transaction CBOR hex here"></textarea>
                        <p class="description">📋 Paste the unsigned transaction hex from your minting flow or other source</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="skey_hex">Extended Private Key (kL||kR)</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                <strong>🔐 Your signing key!</strong> The 128-character extended private key required for CIP-1852 compliant Ed25519 signing. This is what creates the cryptographic proof that you authorize the transaction.
                            </span>
                        </span>
                    </th>
                    <td>
                        <input type="text" name="skey_hex" id="skey_hex"
                               value="<?php echo $saved_wallet ? esc_attr($saved_wallet['payment_skey_extended']) : ''; ?>"
                               style="width: 100%; font-family: monospace; font-size: 11px; <?php echo $saved_wallet ? 'background: #fff3cd; border: 2px solid #f59e0b;' : ''; ?>"
                               placeholder="128-character extended private key hex (kL||kR)">
                        <p class="description">
                            <?php if ($saved_wallet): ?>
                                🔐 Auto-filled from saved test wallet (128 chars)
                            <?php else: ?>
                                Use the 128-character extended key (payment_skey_extended) from Configuration tab
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit" style="margin: 20px 0 0 0; padding: 0;">
                <button type="submit" name="test_signing" class="button button-primary" style="background: #9c27b0; border-color: #7b1fa2; font-size: 15px; padding: 8px 20px; height: auto;">
                    ✍️ Sign Transaction
                </button>
            </p>
        </form>
        </div>

        <!-- System Info (Collapsible) -->
        <details style="background: #f5f5f5; border: 2px solid #9e9e9e; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
            <summary style="cursor: pointer; font-size: 16px; font-weight: 600; color: #424242; margin-bottom: 15px;">
                ⚙️ System Information
            </summary>
            <h2 style="margin-top: 15px;">System Info</h2>
        <ul>
            <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
            <li><strong>Sodium Available:</strong> <?php echo function_exists('sodium_crypto_sign_keypair') ? '✅ Yes' : '❌ No'; ?></li>
            <li><strong>Blake2b Support:</strong> <?php
                if (in_array('blake2b', hash_algos())) {
                    echo '✅ Yes (native hash)';
                } elseif (function_exists('sodium_crypto_generichash')) {
                    echo '✅ Yes (via Sodium)';
                } else {
                    echo '❌ No';
                }
            ?></li>
            <li><strong>OpenSSL Available:</strong> <?php echo extension_loaded('openssl') ? '✅ Yes' : '❌ No'; ?></li>
        </ul>
        </details>

        <!-- Beta Warning -->
        <div style="background: linear-gradient(135deg, #fff3cd 0%, #fde68a 100%); border: 2px solid #f59e0b; padding: 20px; border-radius: 10px; margin: 40px 0 30px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <p style="margin: 0; color: #92400e; font-size: 14px; line-height: 1.8;">
                <strong>⚠️ Beta Software:</strong> This plugin is currently in beta. Please report any bugs you encounter. <strong>Use for real transactions at your own risk.</strong> Always understand what you are doing before executing transactions on mainnet.
            </p>
        </div>

        <!-- Footer -->
        <div style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border: 2px solid #d1d5db; padding: 25px; border-radius: 10px; margin-bottom: 30px; text-align: center;">
            <p style="margin: 0 0 5px 0; color: #6b7280; font-size: 13px; line-height: 1.8;">
                <strong>PHP Cardano Utilities</strong> | Built with ❤️ by <strong>Pb</strong>
            </p>
            <p style="margin: 0 0 15px 0; color: #9ca3af; font-size: 11px; font-style: italic;">
                Not a dev! 😄
            </p>
            <p style="margin: 0; color: #9ca3af; font-size: 12px;">
                <a href="#" id="show-disclaimer" style="color: #667eea; text-decoration: underline; font-weight: 600;">View Disclaimer & Legal</a>
            </p>
        </div>

    </div>
    <?php

    // Flush output buffer for normal rendering
    if (ob_get_level()) ob_end_flush();
}

/**
 * Render the Configuration tab
 */
function render_configuration_tab($saved_wallet) {
    $mainnet_api_key = get_option('cardano_wallet_test_anvil_key_mainnet', '');
    $preprod_api_key = get_option('cardano_wallet_test_anvil_key_preprod', '');

    // Check for action responses (stored in transient for display)
    $response_message = get_transient('cardano_wallet_response_message');
    $response_type = get_transient('cardano_wallet_response_type'); // 'success' or 'error'
    $response_data = get_transient('cardano_wallet_response_data'); // Optional additional data

    // Clear transients after reading
    if ($response_message) {
        delete_transient('cardano_wallet_response_message');
        delete_transient('cardano_wallet_response_type');
        delete_transient('cardano_wallet_response_data');
    }
    ?>
    <div class="cardano-tab-content">

        <?php if ($response_message): ?>
        <!-- Response Display Box -->
        <div style="background: <?php echo $response_type == 'success' ? '#d4edda' : '#f8d7da'; ?>;
                    border: 2px solid <?php echo $response_type == 'success' ? '#28a745' : '#dc3545'; ?>;
                    padding: 20px;
                    margin-bottom: 30px;
                    border-radius: 8px;
                    animation: slideDown 0.3s ease-out;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 10px 0; color: <?php echo $response_type == 'success' ? '#155724' : '#721c24'; ?>;">
                        <?php echo $response_type == 'success' ? '✅ Success!' : '❌ Error'; ?>
                    </h3>
                    <p style="margin: 0; color: <?php echo $response_type == 'success' ? '#155724' : '#721c24'; ?>; font-size: 14px;">
                        <?php echo wp_kses_post($response_message); ?>
                    </p>

                    <?php if ($response_data && is_array($response_data)): ?>
                    <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 5px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto;">
                        <?php if (isset($response_data['formatted'])): ?>
                            <?php echo $response_data['formatted']; ?>
                        <?php else: ?>
                            <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html(json_encode($response_data, JSON_PRETTY_PRINT)); ?></pre>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <button type="button" onclick="this.parentElement.parentElement.style.display='none'"
                        style="background: none; border: none; font-size: 24px; cursor: pointer; color: <?php echo $response_type == 'success' ? '#155724' : '#721c24'; ?>; padding: 0 10px;">
                    ×
                </button>
            </div>
        </div>

        <style>
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
        <?php endif; ?>

        <!-- Tooltip Styles -->
        <style>
            .tooltip-container {
                position: relative;
                display: inline-block;
                margin-left: 5px;
            }
            .tooltip-icon {
                display: inline-block;
                width: 18px;
                height: 18px;
                line-height: 18px;
                text-align: center;
                background: #2271b1;
                color: white;
                border-radius: 50%;
                font-size: 12px;
                font-weight: bold;
                cursor: help;
                vertical-align: middle;
            }
            .tooltip-text {
                visibility: hidden;
                width: 320px;
                background-color: #2c3e50;
                color: #fff;
                text-align: left;
                border-radius: 6px;
                padding: 12px;
                position: absolute;
                z-index: 1000;
                bottom: 125%;
                left: 50%;
                margin-left: -160px;
                opacity: 0;
                transition: opacity 0.3s;
                font-size: 13px;
                line-height: 1.6;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            }
            .tooltip-text::after {
                content: "";
                position: absolute;
                top: 100%;
                left: 50%;
                margin-left: -5px;
                border-width: 5px;
                border-style: solid;
                border-color: #2c3e50 transparent transparent transparent;
            }
            .tooltip-container:hover .tooltip-text {
                visibility: visible;
                opacity: 1;
            }
            .security-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                margin-left: 10px;
                vertical-align: middle;
            }
            .badge-public {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .badge-private {
                background: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            .badge-secret {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
        </style>

        <!-- Getting Started Guide -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: 2px solid #3730a3; padding: 35px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <div style="background: rgba(255,255,255,0.15); padding: 25px; border-radius: 8px; margin-bottom: 25px;">
                <h2 style="margin-top: 0; color: white; font-size: 24px;">🚀 Ship Cardano Products in Minutes</h2>
                <p style="font-size: 16px; margin: 0; color: white; opacity: 0.95;">Create CIP-1852 compliant Cardano wallets, build and submit transactions with Anvil API, and sign with pure PHP—no external dependencies required.</p>
            </div>

            <!-- Step by Step -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin: 0 0 25px 0;">
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 8px; border-left: 4px solid #ffd700; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 2.5em; margin-bottom: 12px; font-weight: bold; color: #ffd700;">1</div>
                    <strong style="font-size: 15px; display: block; margin-bottom: 8px; color: white;">Get Anvil API Keys</strong>
                    <small style="opacity: 0.9; color: white;"><a href="https://ada-anvil.io/services/api" target="_blank" style="color: #ffd700; text-decoration: underline;">Get free keys →</a></small>
                </div>
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 8px; border-left: 4px solid #4caf50; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 2.5em; margin-bottom: 12px; font-weight: bold; color: #4caf50;">2</div>
                    <strong style="font-size: 15px; display: block; margin-bottom: 8px; color: white;">Generate Test Wallet</strong>
                    <small style="opacity: 0.9; color: white;">Save it in the form below</small>
                </div>
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 8px; border-left: 4px solid #ff9800; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 2.5em; margin-bottom: 12px; font-weight: bold; color: #ff9800;">3</div>
                    <strong style="font-size: 15px; display: block; margin-bottom: 8px; color: white;">Fund with Testnet ADA</strong>
                    <small style="opacity: 0.9; color: white;"><a href="https://docs.cardano.org/cardano-testnet/tools/faucet/" target="_blank" style="color: #ffd700; text-decoration: underline;">Get tADA →</a></small>
                </div>
                <div style="background: rgba(255,255,255,0.2); padding: 20px; border-radius: 8px; border-left: 4px solid #9c27b0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div style="font-size: 2.5em; margin-bottom: 12px; font-weight: bold; color: #e1bee7;">4</div>
                    <strong style="font-size: 15px; display: block; margin-bottom: 8px; color: white;">Build & Submit Transactions</strong>
                    <small style="opacity: 0.9; color: white;">Head to the Transactions tab</small>
                </div>
            </div>

            <!-- Use Cases -->
            <div style="background: rgba(255,255,255,0.25); padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <strong style="font-size: 16px; display: block; margin-bottom: 15px; color: white;">💡 Perfect For:</strong>
                <ul style="margin: 0; padding-left: 20px; line-height: 1.8; color: white;">
                    <li><strong>Minting NFTs</strong> with secure back-end signing via native PHP</li>
                    <li><strong>Custodial wallets</strong> for WordPress users via CardanoPress integration</li>
                    <li><strong>Passwordless authentication</strong> for your applications and dApps</li>
                    <li><strong>E-commerce</strong> with Cardano payment integration in seconds</li>
                    <li><strong>SaaS products</strong> requiring programmatic wallet management</li>
                    <li><strong>Educational platforms</strong> teaching Cardano development</li>
                </ul>
            </div>
        </div>

        <!-- Anvil API Keys Section -->
        <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; padding: 25px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: #92400e;">
                🔑 Anvil API Configuration
                <span class="tooltip-container">
                    <span class="tooltip-icon">?</span>
                    <span class="tooltip-text">
                        The Anvil API is required to build and submit your Cardano transactions! Building transactions on Cardano can be tricky—the Anvil API ensures we always build and submit transactions the right way to the blockchain. Think of it like shipping your math homework to your friend who you know will nail it every time—but here it's not even cheating!
                    </span>
                </span>
            </h2>

        <form method="post" action="?page=cardano-wallet-test&tab=configuration" style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <?php wp_nonce_field('save_anvil_keys'); ?>

            <table class="form-table">
                <tr>
                    <th style="width: 200px;"><label for="anvil_api_key_mainnet">🔴 Mainnet API Key</label></th>
                    <td>
                        <input type="text" name="anvil_api_key_mainnet" id="anvil_api_key_mainnet" value="<?php echo esc_attr($mainnet_api_key); ?>" style="width: 100%; max-width: 500px; font-family: monospace;" placeholder="mainnet_xxxxxxxxxxxxxxxxxxxxx">
                        <p class="description">Production API key for mainnet transactions (real ADA!)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="anvil_api_key_preprod">🟢 Preprod API Key</label></th>
                    <td>
                        <input type="text" name="anvil_api_key_preprod" id="anvil_api_key_preprod" value="<?php echo esc_attr($preprod_api_key); ?>" style="width: 100%; max-width: 500px; font-family: monospace;" placeholder="testnet_xxxxxxxxxxxxxxxxxxxxx">
                        <p class="description">Testing API key for preprod/testnet transactions (tADA)</p>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <button type="submit" name="save_anvil_keys" class="button button-primary" style="padding: 8px 20px;">💾 Save API Keys</button>
                        <p class="description" style="margin-top: 10px;">Get your free API keys from <a href="https://ada-anvil.io/services/api" target="_blank">Anvil API Services</a></p>
                    </td>
                </tr>
            </table>
        </form>

        <?php if (!empty($mainnet_api_key) || !empty($preprod_api_key)): ?>
        <!-- Test API Connection -->
        <form method="post" action="?page=cardano-wallet-test&tab=configuration" style="background: rgba(255,255,255,0.6); padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <?php wp_nonce_field('test_anvil_connection'); ?>

            <table class="form-table" style="margin: 0;">
                <tr>
                    <th style="width: 200px;">
                        <label for="test_network">Test Connection</label>
                        <span class="tooltip-container">
                            <span class="tooltip-icon">?</span>
                            <span class="tooltip-text">
                                Test your Anvil API keys with the click of a button and ensure you have the required access to start using the API today!
                            </span>
                        </span>
                    </th>
                    <td>
                        <select name="test_network" id="test_network" style="width: 200px;">
                            <option value="preprod" selected>Preprod (Testnet)</option>
                            <option value="mainnet">Mainnet</option>
                        </select>
                        <button type="submit" name="test_anvil_connection" class="button" style="margin-left: 10px;">🔌 Test API Connection</button>
                        <p class="description">Verify your API key is valid</p>
                    </td>
                </tr>
            </table>
        </form>
        <?php endif; ?>
        </div>

        <hr style="margin: 40px 0;">

        <!-- Test Wallet Section -->
        <h2>💼 Test Wallet (Persistent Storage)
            <span class="tooltip-container">
                <span class="tooltip-icon" style="background: #9c27b0;">⭐</span>
                <span class="tooltip-text">
                    <strong>✨ The Magic:</strong> These wallets are generated directly on YOUR server—not on any shared infrastructure! It's like making a wallet at home on paper, but automated. If your server is secured properly, there's no way for someone else to get your seed. This is quite cool!
                </span>
            </span>
        </h2>

        <div style="background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 2px solid #0ea5e9; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <p style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.6;">
                Generate and save a CIP-1852 compliant test wallet for development. This shows <strong>ALL wallet components</strong> so you can understand what each part does.
            </p>
            <p style="margin: 0; font-size: 13px; color: #0369a1;">
                <strong>🔒 Security Note:</strong> Some parts of your wallet can be shared publicly (like addresses and public keys), while others must remain secret (private keys and mnemonic). Each field below is labeled accordingly.
            </p>
        </div>

        <?php
        if ($saved_wallet):
        ?>
            <div style="background: #e8f5e9; border: 2px solid #4caf50; padding: 20px; margin-bottom: 20px; border-radius: 5px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">
                        ✅ Saved Test Wallet (<?php echo esc_html($saved_wallet['network'] ?? 'preprod'); ?>)
                        <span class="tooltip-container">
                            <span class="tooltip-icon" style="background: #f59e0b;">⚠️</span>
                            <span class="tooltip-text">
                                <strong>Transaction Failing?</strong> If you get "MissingVKeyWitnessesUTXOW" errors, your saved wallet's address doesn't match its private key. Click "Clear & Regenerate" to create a fresh wallet.
                            </span>
                        </span>
                    </h3>
                    <form method="post" action="?page=cardano-wallet-test&tab=configuration" style="margin: 0;">
                        <?php wp_nonce_field('clear_test_wallet'); ?>
                        <button type="submit" name="clear_test_wallet" class="button" onclick="return confirm('Clear saved test wallet and generate a new one?')">
                            🔄 Clear & Regenerate
                        </button>
                    </form>
                </div>

                <!-- Full Wallet Display -->
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th colspan="2" style="background: #f0f0f1; padding: 10px;"><strong>📍 Addresses</strong></th>
                    </tr>
                    <tr>
                        <th style="width: 220px;">
                            Payment Address
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    Your wallet's payment address—where you receive ADA and tokens. This is safe to share publicly!
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['payment_address']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-public">✅ OK to share publicly!</span>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Stake Address
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    Your wallet's stake address—used for delegation and rewards. Safe to share!
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['stake_address']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-public">✅ OK to share publicly!</span>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2" style="background: #f0f0f1; padding: 10px; border-top: 2px solid #ddd;"><strong>💳 Payment Keys</strong></th>
                    </tr>
                    <?php if (isset($saved_wallet['payment_pkey_hex'])): ?>
                    <tr>
                        <th>
                            Payment Public Key
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    The public key derived from your private key. Used for verification. Safe to share!
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['payment_pkey_hex']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-public">✅ OK to share publicly!</span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>
                            Payment Key Hash
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    Hash of your public key—used in address derivation. Safe to share!
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['payment_keyhash']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-public">✅ OK to share publicly!</span>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Payment Private Key (kL)<br><small>64 chars</small>
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    First half of the extended key (kL). Keep this private! Sharing this compromises your wallet.
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['payment_skey_hex']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px; background: #fff3cd;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-private">⚠️ Keep private!</span>
                            <p class="description">First 64 chars of extended key (kL only)</p>
                        </td>
                    </tr>
                    <?php if (isset($saved_wallet['payment_skey_extended'])): ?>
                    <tr>
                        <th>
                            🔐 Payment Extended Key (kL||kR)<br><small>128 chars</small>
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    <strong>⭐ This is what signs transactions!</strong> The full 128-character extended private key. Use this for programmatic signing with EXTREME caution. Anyone with this key can spend from your wallet. Perfect for server-side custodial operations when properly secured.
                                </span>
                            </span>
                        </th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['payment_skey_extended']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px; background: #ffebee; border: 2px solid #f44336;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-secret">🔒 NEVER share this!</span>
                            <p class="description"><strong>⭐ Use THIS for signing transactions!</strong> Full extended key (<?php echo strlen($saved_wallet['payment_skey_extended']); ?> chars)</p>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($saved_wallet['stake_keyhash']) || isset($saved_wallet['stake_skey_hex']) || isset($saved_wallet['stake_skey_extended'])): ?>
                    <tr>
                        <th colspan="2" style="background: #f0f0f1; padding: 10px; border-top: 2px solid #ddd;"><strong>🎯 Stake Keys</strong></th>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($saved_wallet['stake_keyhash'])): ?>
                    <tr>
                        <th>Stake Key Hash</th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['stake_keyhash']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($saved_wallet['stake_skey_hex'])): ?>
                    <tr>
                        <th>Stake Private Key (kL)<br><small>64 chars</small></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['stake_skey_hex']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px; background: #fff3cd;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (isset($saved_wallet['stake_skey_extended'])): ?>
                    <tr>
                        <th>Stake Extended Key (kL||kR)<br><small>128 chars</small></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr($saved_wallet['stake_skey_extended']); ?>" readonly style="width: 100%; font-family: monospace; font-size: 11px; background: #fff3cd;">
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th colspan="2" style="background: #f0f0f1; padding: 10px; border-top: 2px solid #ddd;"><strong>🔑 Recovery Phrase</strong></th>
                    </tr>
                    <tr>
                        <th>
                            24-Word Mnemonic
                            <span class="tooltip-container">
                                <span class="tooltip-icon">?</span>
                                <span class="tooltip-text">
                                    <strong>🔑 Your Recovery Phrase:</strong> This 24-word mnemonic is the master key to your entire wallet. Anyone with these words can recreate ALL your keys and spend from your wallet. Store this securely offline and NEVER share it with anyone!
                                </span>
                            </span>
                        </th>
                        <td>
                            <textarea readonly style="width: 100%; font-family: monospace; font-size: 12px; background: #ffebee; border: 2px solid #f44336; height: 80px; padding: 10px;"><?php echo esc_textarea($saved_wallet['mnemonic']); ?></textarea>
                            <button type="button" class="button button-small" onclick="copyToClipboard(this)" style="margin-top: 5px;">📋 Copy</button>
                            <span class="security-badge badge-secret">🔒 NEVER share this!</span>
                            <p class="description">Generated: <?php echo esc_html($saved_wallet['generated_at'] ?? 'N/A'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        <?php else: ?>
            <!-- Generate New Wallet Form -->
            <form method="post" action="?page=cardano-wallet-test&tab=configuration" style="background: #f0f9ff; padding: 20px; border: 2px solid #0ea5e9; margin-bottom: 20px; border-radius: 5px;">
                <?php wp_nonce_field('save_test_wallet'); ?>

                <p><strong>No saved test wallet found.</strong> Generate one to use for testing:</p>

                <table class="form-table">
                    <tr>
                        <th style="width: 150px;"><label for="save_network">Network</label></th>
                        <td>
                            <select name="save_network" id="save_network" style="width: 200px;">
                                <option value="preprod" selected>Preprod (Testnet)</option>
                                <option value="mainnet">Mainnet</option>
                            </select>
                            <button type="submit" name="save_test_wallet" class="button button-primary" style="margin-left: 10px;">
                                💾 Generate & Save Test Wallet
                            </button>
                            <p class="description">This wallet will persist until you clear it. Recommended: Start with Preprod for testing.</p>
                        </td>
                    </tr>
                </table>
            </form>
        <?php endif; ?>

        <hr style="margin: 40px 0;">

        <!-- Help & Resources -->
        <div style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196f3; padding: 25px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h3 style="margin-top: 0; color: #0d47a1;">📚 Help & Resources</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                <div style="background: rgba(255,255,255,0.5); padding: 15px; border-radius: 8px;">
                    <h4 style="color: #0d47a1; margin-top: 0;">🔧 Anvil API</h4>
                    <ul style="margin: 0; padding-left: 20px; line-height: 1.8; color: #1565c0;">
                        <li><a href="https://github.com/Cardano-Forge/anvil-api" target="_blank" style="color: #0d47a1;">GitHub Repository</a> - API documentation and examples</li>
                        <li><a href="https://dev.ada-anvil.io/" target="_blank" style="color: #0d47a1;">Developer Documentation</a> - Complete API reference</li>
                    </ul>
                </div>
                <div style="background: rgba(255,255,255,0.5); padding: 15px; border-radius: 8px;">
                    <h4 style="color: #0d47a1; margin-top: 0;">🔌 CardanoPress Integration</h4>
                    <ul style="margin: 0; padding-left: 20px; line-height: 1.8; color: #1565c0;">
                        <li><a href="https://cardanopress.io/" target="_blank" style="color: #0d47a1;">CardanoPress.io</a> - CIP-30 wallet connections</li>
                        <li><strong>Note:</strong> CardanoPress handles external wallet connections and automatic WordPress account creation—essential for production dApps!</li>
                    </ul>
                </div>
            </div>
            <p style="margin: 20px 0 0 0; padding: 15px; background: rgba(255,255,255,0.6); border-left: 3px solid #0d47a1; border-radius: 4px; color: #1565c0;">
                <strong>💡 Tip:</strong> This plugin focuses on server-side wallet generation and transaction signing. Combine it with CardanoPress for a complete solution: use CardanoPress for CIP-30 wallet connections (user-side) and this plugin for custodial operations (server-side).
            </p>
        </div>

        <!-- System Info -->
        <details style="margin-bottom: 20px;">
            <summary style="cursor: pointer; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; font-weight: bold;">
                ⚙️ System Information (click to expand)
            </summary>
            <ul style="list-style: none; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-top: none; border-radius: 0 0 5px 5px; margin-top: 0;">
                <li style="padding: 5px 0;"><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                <li style="padding: 5px 0;"><strong>Sodium Available:</strong> <?php echo function_exists('sodium_crypto_sign_keypair') ? '✅ Yes' : '❌ No'; ?></li>
                <li style="padding: 5px 0;"><strong>Blake2b Support:</strong> <?php
                    if (in_array('blake2b', hash_algos())) {
                        echo '✅ Yes (native hash)';
                    } elseif (function_exists('sodium_crypto_generichash')) {
                        echo '✅ Yes (via Sodium)';
                    } else {
                        echo '❌ No';
                    }
                ?></li>
                <li style="padding: 5px 0;"><strong>OpenSSL Available:</strong> <?php echo extension_loaded('openssl') ? '✅ Yes' : '❌ No'; ?></li>
            </ul>
        </details>

        <!-- Beta Warning -->
        <div style="background: linear-gradient(135deg, #fff3cd 0%, #fde68a 100%); border: 2px solid #f59e0b; padding: 20px; border-radius: 10px; margin: 40px 0 30px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <p style="margin: 0; color: #92400e; font-size: 14px; line-height: 1.8;">
                <strong>⚠️ Beta Software:</strong> This plugin is currently in beta. Please report any bugs you encounter. <strong>Use for real transactions at your own risk.</strong> Always understand what you are doing before executing transactions on mainnet.
            </p>
        </div>

        <!-- Footer -->
        <div style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border: 2px solid #d1d5db; padding: 25px; border-radius: 10px; margin-bottom: 30px; text-align: center;">
            <p style="margin: 0 0 5px 0; color: #6b7280; font-size: 13px; line-height: 1.8;">
                <strong>PHP Cardano Utilities</strong> | Built with ❤️ by <strong>Pb</strong>
            </p>
            <p style="margin: 0 0 15px 0; color: #9ca3af; font-size: 11px; font-style: italic;">
                Not a dev! 😄
            </p>
            <p style="margin: 0; color: #9ca3af; font-size: 12px;">
                <a href="#" class="show-disclaimer-link" style="color: #667eea; text-decoration: underline; font-weight: 600;">View Disclaimer & Legal</a>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Render the Tip Widget configuration page
 */
function render_tip_widget_config() {
    // Get saved settings
    $settings = get_option('cardano_tip_widget_settings', array(
        'enabled' => false,
        'recipient_address' => '',
        'network' => 'preprod',
        'widget_title' => 'Support My Work',
        'widget_description' => 'Send a tip in ADA to support what I do!',
        'button_text' => 'Send Tip',
        'preset_amounts' => array(5, 10, 25, 50),
        'allow_custom_amount' => true,
        'min_amount' => 1,
        'allow_message' => true,
        'thank_you_message' => 'Thank you for your generous tip! Your support means the world to me.',
        'color_scheme' => 'purple',
        'widget_width' => 'medium',
        'button_style' => 'solid',
        'widget_opacity' => 1.0,
    ));
    ?>

    <div style="margin-left: 20px;">
        <!-- Hero Introduction Section -->
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: 2px solid #3730a3; padding: 35px; border-radius: 10px; margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <div style="background: rgba(255,255,255,0.15); padding: 25px; border-radius: 8px;">
                <h2 style="margin-top: 0; color: white; font-size: 28px; font-weight: 800;">💰 Cardano Tip Widget Demo</h2>
                <p style="font-size: 17px; margin: 0; color: white; opacity: 0.95; line-height: 1.7; font-weight: 500;">
                    Showcasing WordPress-native Cardano transactions with pure PHP — zero external dependencies, just CardanoPress + Anvil API + your existing site.
                </p>
            </div>
        </div>

        <!-- Shortcode Information -->
        <div style="background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%); border: 2px solid #2196f3; padding: 20px; border-radius: 10px; margin-bottom: 30px;">
            <div style="display: flex; align-items: center; gap: 20px;">
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 8px 0; color: #0d47a1; font-size: 16px;">📝 Add to Any Page</h3>
                    <p style="color: #1565c0; margin: 0; font-size: 13px;">Drop this shortcode anywhere on your WordPress site:</p>
                </div>
                <div style="background: rgba(255,255,255,0.7); padding: 12px 20px; border-radius: 8px;">
                    <code style="font-size: 16px; font-weight: 700; color: #0d47a1;">[cardano_tip]</code>
                </div>
            </div>
        </div>

        <!-- Two-Column Layout: Config + Preview -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;">

            <!-- LEFT COLUMN: Widget Configuration Form -->
            <div>
                <form method="post" action="">
                    <?php wp_nonce_field('save_tip_widget'); ?>

                    <div style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 2px solid #f59e0b; padding: 25px; border-radius: 10px;">
                        <h3 style="margin-top: 0; color: #92400e; font-size: 18px;">⚙️ Widget Configuration</h3>

                <!-- Enable/Disable Widget -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="tip_widget_enabled" value="1" <?php checked($settings['enabled'], true); ?> style="width: 18px; height: 18px;">
                        <span style="font-weight: 600; color: #92400e; font-size: 15px;">Enable Tip Widget</span>
                    </label>
                    <p style="margin: 10px 0 0 28px; color: #92400e; font-size: 13px;">When disabled, the shortcode will not display anything on your site.</p>
                </div>

                <!-- Recipient Address -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">
                        Recipient Address (Where Tips Go) <span style="color: #dc2626;">*</span>
                    </label>
                    <input type="text" name="tip_recipient_address" value="<?php echo esc_attr($settings['recipient_address']); ?>"
                           placeholder="addr_test1... or addr1..." required
                           style="width: 100%; padding: 12px; border: 2px solid #f59e0b; border-radius: 6px; font-family: monospace; font-size: 13px;">
                    <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">⚠️ Double-check this address! All tips will be sent here.</p>
                </div>

                <!-- Network Selection -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">
                        Network <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="tip_network" required style="padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; font-size: 14px;">
                        <option value="preprod" <?php selected($settings['network'], 'preprod'); ?>>Preprod Testnet (for testing)</option>
                        <option value="mainnet" <?php selected($settings['network'], 'mainnet'); ?>>Mainnet (real ADA)</option>
                    </select>
                    <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">💡 Start with Preprod to test before going live with Mainnet!</p>
                </div>

                <!-- Widget Display Settings -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; color: #92400e;">🎨 Display Settings</h4>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Widget Title</label>
                    <input type="text" name="tip_widget_title" value="<?php echo esc_attr($settings['widget_title']); ?>"
                           placeholder="Support My Work"
                           style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; margin-bottom: 15px;">

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Description</label>
                    <textarea name="tip_widget_description" rows="2"
                              placeholder="Send a tip in ADA to support what I do!"
                              style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; margin-bottom: 15px;"><?php echo esc_textarea($settings['widget_description']); ?></textarea>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Button Text</label>
                    <input type="text" name="tip_button_text" value="<?php echo esc_attr($settings['button_text']); ?>"
                           placeholder="Send Tip"
                           style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px;">
                </div>

                <!-- Tip Amounts -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; color: #92400e;">💵 Tip Amounts</h4>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Preset Amounts (comma-separated ADA)</label>
                    <input type="text" name="tip_preset_amounts" value="<?php echo esc_attr(implode(', ', $settings['preset_amounts'])); ?>"
                           placeholder="5, 10, 25, 50"
                           style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; margin-bottom: 15px;">
                    <p style="margin: 0 0 15px 0; color: #92400e; font-size: 13px;">Example: 5, 10, 25, 50 will create four preset buttons.</p>

                    <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer;">
                        <input type="checkbox" name="tip_allow_custom" value="1" <?php checked($settings['allow_custom_amount'], true); ?> style="width: 18px; height: 18px;">
                        <span style="font-weight: 600; color: #92400e;">Allow Custom Amount</span>
                    </label>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Minimum Tip Amount (ADA)</label>
                    <input type="number" name="tip_min_amount" value="<?php echo esc_attr($settings['min_amount']); ?>"
                           min="1" step="0.01"
                           style="width: 200px; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px;">
                    <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">Minimum: 1 ADA (blockchain requirement for outputs)</p>
                </div>

                <!-- Message Settings -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; color: #92400e;">💬 Message Settings</h4>

                    <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; cursor: pointer;">
                        <input type="checkbox" name="tip_allow_message" value="1" <?php checked($settings['allow_message'], true); ?> style="width: 18px; height: 18px;">
                        <span style="font-weight: 600; color: #92400e;">Allow Tipper to Include Message (CIP-20 Metadata)</span>
                    </label>
                    <p style="margin: 0 0 15px 28px; color: #92400e; font-size: 13px;">Messages are stored on-chain as transaction metadata. Perfect for notes, dedications, or messages of support!</p>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Thank You Message</label>
                    <textarea name="tip_thank_you_message" rows="3"
                              placeholder="Thank you for your generous tip!"
                              style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px;"><?php echo esc_textarea($settings['thank_you_message']); ?></textarea>
                    <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">Shown to the tipper after successful transaction submission.</p>
                </div>

                <!-- Design Settings -->
                <div style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; color: #92400e;">🎨 Design Settings</h4>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Color Scheme</label>
                    <select name="tip_color_scheme" style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; margin-bottom: 15px;">
                        <option value="purple" <?php selected($settings['color_scheme'], 'purple'); ?>>Purple (Default)</option>
                        <option value="blue" <?php selected($settings['color_scheme'], 'blue'); ?>>Blue</option>
                        <option value="green" <?php selected($settings['color_scheme'], 'green'); ?>>Green</option>
                        <option value="orange" <?php selected($settings['color_scheme'], 'orange'); ?>>Orange</option>
                        <option value="pink" <?php selected($settings['color_scheme'], 'pink'); ?>>Pink</option>
                        <option value="dark" <?php selected($settings['color_scheme'], 'dark'); ?>>Dark</option>
                        <option value="glass" <?php selected($settings['color_scheme'], 'glass'); ?>>✨ Glassmorphism (Umbrella Theme)</option>
                    </select>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Widget Opacity</label>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                        <input type="range" name="tip_widget_opacity" id="tip_opacity_slider"
                               min="0.1" max="1.0" step="0.05"
                               value="<?php echo esc_attr($settings['widget_opacity']); ?>"
                               style="flex: 1; height: 6px; border-radius: 3px; background: linear-gradient(90deg, rgba(245, 158, 11, 0.3) 0%, #f59e0b 100%); outline: none; cursor: pointer;">
                        <span id="tip_opacity_value" style="min-width: 45px; font-weight: 700; color: #92400e; font-size: 14px;"><?php echo esc_html(number_format($settings['widget_opacity'], 2)); ?></span>
                    </div>
                    <p style="margin: 0 0 15px 0; color: #92400e; font-size: 13px;">Control widget transparency (0.1 = very transparent, 1.0 = fully opaque)</p>
                    <script>
                        document.getElementById('tip_opacity_slider').addEventListener('input', function(e) {
                            document.getElementById('tip_opacity_value').textContent = parseFloat(e.target.value).toFixed(2);
                        });
                    </script>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Widget Width</label>
                    <select name="tip_widget_width" style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px; margin-bottom: 15px;">
                        <option value="small" <?php selected($settings['widget_width'], 'small'); ?>>Small (400px)</option>
                        <option value="medium" <?php selected($settings['widget_width'], 'medium'); ?>>Medium (500px)</option>
                        <option value="large" <?php selected($settings['widget_width'], 'large'); ?>>Large (600px)</option>
                        <option value="full" <?php selected($settings['widget_width'], 'full'); ?>>Full Width</option>
                    </select>

                    <label style="display: block; font-weight: 600; color: #92400e; margin-bottom: 8px;">Button Style</label>
                    <select name="tip_button_style" style="width: 100%; padding: 10px; border: 2px solid #f59e0b; border-radius: 6px;">
                        <option value="solid" <?php selected($settings['button_style'], 'solid'); ?>>Solid</option>
                        <option value="outline" <?php selected($settings['button_style'], 'outline'); ?>>Outline</option>
                        <option value="rounded" <?php selected($settings['button_style'], 'rounded'); ?>>Rounded</option>
                    </select>
                    <p style="margin: 8px 0 0 0; color: #92400e; font-size: 13px;">Customize the appearance to match your site's design!</p>
                </div>

                        <!-- Save Button -->
                        <button type="submit" name="save_tip_widget"
                                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 15px 30px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3); transition: all 0.2s; width: 100%;"
                                onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(102, 126, 234, 0.4)';"
                                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 8px rgba(102, 126, 234, 0.3)';">
                            💾 Save Widget Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT COLUMN: Live Preview -->
            <div style="position: sticky; top: 20px;">
                <?php if ($settings['enabled'] && !empty($settings['recipient_address'])) : ?>
                    <div style="background: linear-gradient(135deg, #f3e5f5 0%, #ce93d8 100%); border: 2px solid #9c27b0; padding: 25px; border-radius: 10px;">
                        <h3 style="margin-top: 0; color: #4a148c; font-size: 18px;">👀 Live Preview</h3>
                        <p style="color: #6a1b9a; margin-bottom: 15px; font-size: 13px;">This is how your widget will appear on your site:</p>
                        <div style="background: white; padding: 20px; border-radius: 8px;">
                            <?php echo do_shortcode('[cardano_tip]'); ?>
                        </div>
                    </div>
                <?php elseif (!$settings['enabled']) : ?>
                    <div style="background: linear-gradient(135deg, #fff8e1 0%, #ffe082 100%); border: 2px solid #ffa000; padding: 25px; border-radius: 10px;">
                        <h3 style="margin-top: 0; color: #e65100; font-size: 18px;">⚠️ Widget Disabled</h3>
                        <p style="margin: 0; color: #e65100; font-size: 14px;">
                            Enable the widget and save to see a live preview!
                        </p>
                    </div>
                <?php else : ?>
                    <div style="background: linear-gradient(135deg, #fff8e1 0%, #ffe082 100%); border: 2px solid #ffa000; padding: 25px; border-radius: 10px;">
                        <h3 style="margin-top: 0; color: #e65100; font-size: 18px;">⚙️ Configuration Needed</h3>
                        <p style="margin: 0; color: #e65100; font-size: 14px;">
                            Please add a recipient address, enable the widget, and save to see your live preview!
                        </p>
                    </div>
                <?php endif; ?>
            </div>

        </div> <!-- End two-column grid -->

        <!-- Branding Footer -->
        <div style="background: rgba(255,255,255,0.6); border: 2px solid #e5e7eb; padding: 20px; border-radius: 10px; text-align: center; margin: 30px 0;">
            <p style="margin: 0; color: #6b7280; font-size: 13px; line-height: 1.8;">
                <strong>Transaction powered by Anvil API & CardanoPress</strong><br>
                <span style="font-size: 11px;">A true native WordPress experience with Cardano</span>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Render the Generation Diagnostics tab
 */
function render_tools_tab() {
    // Get the active tool (default to proof)
    $active_tool = isset($_GET['tool']) ? sanitize_text_field($_GET['tool']) : 'proof';
    ?>
    <div class="cardano-tab-content">
        <h2>🛠️ Generation Diagnostics</h2>
        <p class="description">Cryptographic proof and diagnostic tools for Cardano wallet generation</p>

        <!-- Sub-tab Navigation -->
        <div class="tool-nav" style="margin: 20px 0; margin-left: 20px; border-bottom: 2px solid #e5e7eb;">
            <a href="?page=cardano-wallet-test&tab=tools&tool=proof"
               class="tool-tab <?php echo $active_tool == 'proof' ? 'active' : ''; ?>"
               style="display: inline-block; padding: 12px 24px; text-decoration: none; border-bottom: 3px solid <?php echo $active_tool == 'proof' ? '#667eea' : 'transparent'; ?>; font-weight: 600; color: <?php echo $active_tool == 'proof' ? '#667eea' : '#6b7280'; ?>; transition: all 0.2s;">
                🔬 Cryptographic Proof
            </a>
            <a href="?page=cardano-wallet-test&tab=tools&tool=witness"
               class="tool-tab <?php echo $active_tool == 'witness' ? 'active' : ''; ?>"
               style="display: inline-block; padding: 12px 24px; text-decoration: none; border-bottom: 3px solid <?php echo $active_tool == 'witness' ? '#667eea' : 'transparent'; ?>; font-weight: 600; color: <?php echo $active_tool == 'witness' ? '#667eea' : '#6b7280'; ?>; transition: all 0.2s;">
                🔍 Witness Set Diagnostics
            </a>
        </div>

        <!-- Tool Content -->
        <div class="tool-content" style="margin-top: 20px;">
            <?php
            if ($active_tool == 'proof') {
                // Load the cryptographic proof (test-master.php)
                echo '<div style="background: #1e1e1e; color: #d4d4d4; margin: 0 -40px -20px -40px; padding: 20px; min-height: 100vh;">';
                require_once plugin_dir_path(__FILE__) . 'test-master.php';
                echo '</div>';
            } elseif ($active_tool == 'witness') {
                // Load the witness diagnostics
                echo '<div style="background: #1e1e1e; color: #d4d4d4; margin: 0 -40px -20px -40px; padding: 20px; min-height: 100vh;">';
                require_once plugin_dir_path(__FILE__) . 'test-witness-diagnostics.php';
                echo '</div>';
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * ============================================================================
 * TIP WIDGET SHORTCODE & FRONTEND
 * ============================================================================
 */

/**
 * Register the [cardano_tip] shortcode
 */
add_shortcode('cardano_tip', 'cardano_tip_widget_shortcode');

function cardano_tip_widget_shortcode($atts) {
    // Get widget settings
    $settings = get_option('cardano_tip_widget_settings', array());

    // Check if widget is enabled
    if (empty($settings['enabled'])) {
        return ''; // Return nothing if disabled
    }

    // Check if recipient address is configured
    if (empty($settings['recipient_address'])) {
        return '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; border-radius: 8px; text-align: center;">
            <p style="margin: 0; color: #856404;">⚠️ Tip widget is not configured. Please set a recipient address in the plugin settings.</p>
        </div>';
    }

    // Check if CardanoPress is available
    if (!function_exists('cardanoPress')) {
        return '<div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; border-radius: 8px; text-align: center;">
            <p style="margin: 0; color: #856404;">⚠️ CardanoPress plugin is required for the tip widget to work.</p>
        </div>';
    }

    // Extract settings with defaults
    $widget_title = !empty($settings['widget_title']) ? $settings['widget_title'] : 'Support My Work';
    $widget_description = !empty($settings['widget_description']) ? $settings['widget_description'] : 'Send a tip in ADA to support what I do!';
    $button_text = !empty($settings['button_text']) ? $settings['button_text'] : 'Send Tip';
    $preset_amounts = !empty($settings['preset_amounts']) ? $settings['preset_amounts'] : array(5, 10, 25, 50);
    $allow_custom = !empty($settings['allow_custom_amount']);
    $min_amount = !empty($settings['min_amount']) ? $settings['min_amount'] : 1;
    $allow_message = !empty($settings['allow_message']);
    $network = !empty($settings['network']) ? $settings['network'] : 'preprod';
    $recipient_address = $settings['recipient_address'];

    // Design settings
    $color_scheme = !empty($settings['color_scheme']) ? $settings['color_scheme'] : 'purple';
    $widget_width = !empty($settings['widget_width']) ? $settings['widget_width'] : 'medium';
    $button_style = !empty($settings['button_style']) ? $settings['button_style'] : 'solid';

    // Color scheme definitions
    $colors = array(
        'purple' => array('gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', 'shadow' => 'rgba(102, 126, 234, 0.3)', 'button_color' => '#667eea', 'is_glass' => false),
        'blue' => array('gradient' => 'linear-gradient(135deg, #2196f3 0%, #1565c0 100%)', 'shadow' => 'rgba(33, 150, 243, 0.3)', 'button_color' => '#2196f3', 'is_glass' => false),
        'green' => array('gradient' => 'linear-gradient(135deg, #4caf50 0%, #2e7d32 100%)', 'shadow' => 'rgba(76, 175, 80, 0.3)', 'button_color' => '#4caf50', 'is_glass' => false),
        'orange' => array('gradient' => 'linear-gradient(135deg, #ff9800 0%, #e65100 100%)', 'shadow' => 'rgba(255, 152, 0, 0.3)', 'button_color' => '#ff9800', 'is_glass' => false),
        'pink' => array('gradient' => 'linear-gradient(135deg, #e91e63 0%, #ad1457 100%)', 'shadow' => 'rgba(233, 30, 99, 0.3)', 'button_color' => '#e91e63', 'is_glass' => false),
        'dark' => array('gradient' => 'linear-gradient(135deg, #424242 0%, #212121 100%)', 'shadow' => 'rgba(66, 66, 66, 0.3)', 'button_color' => '#424242', 'is_glass' => false),
        'glass' => array('gradient' => 'linear-gradient(135deg, rgba(0, 51, 173, 0.15), rgba(0, 212, 255, 0.1))', 'shadow' => 'rgba(0, 0, 0, 0.3)', 'button_color' => '#00d4ff', 'is_glass' => true),
    );

    $current_color = $colors[$color_scheme];
    $widget_opacity = !empty($settings['widget_opacity']) ? $settings['widget_opacity'] : 1.0;

    // Widget width
    $width_values = array(
        'small' => '400px',
        'medium' => '500px',
        'large' => '600px',
        'full' => '100%'
    );
    $max_width = $width_values[$widget_width];

    // Button styles
    $button_bg = 'white';
    $button_color = $current_color['button_color'];
    $button_border = 'none';
    $button_radius = '8px';

    if ($current_color['is_glass']) {
        // Glassmorphism button style
        $button_bg = 'rgba(255, 255, 255, 0.1)';
        $button_color = 'white';
        $button_border = '1px solid rgba(0, 212, 255, 0.4)';
    } elseif ($button_style === 'outline') {
        $button_bg = 'transparent';
        $button_color = 'white';
        $button_border = '2px solid white';
    } elseif ($button_style === 'rounded') {
        $button_radius = '50px';
    }

    // Generate unique ID for this widget instance
    $widget_id = 'cardano-tip-' . uniqid();

    ob_start();
    ?>

    <?php
    // Build widget card styles
    $widget_card_style = "border-radius: 12px; padding: 30px; text-align: center; opacity: {$widget_opacity};";

    if ($current_color['is_glass']) {
        // Glassmorphism style (umbrella theme)
        $widget_card_style .= "background: {$current_color['gradient']}; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); box-shadow: 0 8px 32px {$current_color['shadow']}, 0 0 0 1px rgba(0, 212, 255, 0.3); border: 1px solid rgba(0, 212, 255, 0.4);";
    } else {
        // Standard gradient style
        $widget_card_style .= "background: {$current_color['gradient']}; box-shadow: 0 8px 24px {$current_color['shadow']};";
    }
    ?>
    <div id="<?php echo esc_attr($widget_id); ?>" class="cardano-tip-widget" style="max-width: <?php echo esc_attr($max_width); ?>; margin: 30px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
        <!-- Widget Card -->
        <div style="<?php echo esc_attr($widget_card_style); ?>">
            <h3 style="color: white; margin: 0 0 10px 0; font-size: 24px; font-weight: 700; text-shadow: 0 2px 4px rgba(0,0,0,0.2);"><?php echo esc_html($widget_title); ?></h3>
            <p style="color: rgba(255,255,255,0.95); margin: 0 0 25px 0; font-size: 15px; line-height: 1.6; text-shadow: 0 1px 2px rgba(0,0,0,0.1);"><?php echo esc_html($widget_description); ?></p>

            <button id="<?php echo esc_attr($widget_id); ?>-btn"
                    style="background: <?php echo esc_attr($button_bg); ?>; color: <?php echo esc_attr($button_color); ?>; border: <?php echo esc_attr($button_border); ?>; padding: 15px 40px; border-radius: <?php echo esc_attr($button_radius); ?>; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: all 0.2s; width: 100%; max-width: 250px;"
                    onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(0,0,0,0.2)';"
                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)';">
                <?php echo esc_html($button_text); ?>
            </button>
        </div>

        <!-- Branding Footer -->
        <div style="text-align: center; margin-top: 15px; padding: 10px;">
            <p style="margin: 0; color: #9ca3af; font-size: 11px; line-height: 1.6;">
                Powered by <strong>Anvil API</strong> & <strong>CardanoPress</strong><br>
                <span style="font-size: 10px;">A native WordPress Cardano experience</span>
            </p>
        </div>
    </div>

    <!-- Tip Modal -->
    <?php
    // Build modal header styles
    $modal_header_style = "padding: 20px; border-radius: 12px 12px 0 0; position: relative;";
    if ($current_color['is_glass']) {
        $modal_header_style .= "background: {$current_color['gradient']}; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid rgba(0, 212, 255, 0.3);";
    } else {
        $modal_header_style .= "background: {$current_color['gradient']};";
    }
    ?>
    <div id="<?php echo esc_attr($widget_id); ?>-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); z-index: 999999; justify-content: center; align-items: center; padding: 20px; overflow-y: auto; box-sizing: border-box;">
        <div style="background: white; max-width: 500px; width: 100%; border-radius: 12px; box-shadow: 0 8px 32px rgba(0,0,0,0.4); position: relative; max-height: 90vh; overflow-y: auto; margin: auto; box-sizing: border-box;">
            <!-- Modal Header -->
            <div style="<?php echo esc_attr($modal_header_style); ?>">
                <h3 style="margin: 0; color: white; font-size: 20px; font-weight: 700; padding-right: 40px; text-shadow: 0 2px 4px rgba(0,0,0,0.2);">Send a Tip</h3>
                <button id="<?php echo esc_attr($widget_id); ?>-close" style="position: absolute; top: 15px; right: 15px; background: rgba(255,255,255,0.2); color: white; border: none; border-radius: 50%; width: 35px; height: 35px; font-size: 20px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)';" onmouseout="this.style.background='rgba(255,255,255,0.2)';">×</button>
            </div>

            <!-- Modal Body -->
            <div id="<?php echo esc_attr($widget_id); ?>-content" style="padding: 20px; box-sizing: border-box;">
                <!-- Step 1: Wallet Connection -->
                <div id="<?php echo esc_attr($widget_id); ?>-step-connect" class="tip-step">
                    <?php if (cardanoPress()->userProfile()->isConnected()) : ?>
                        <div style="background: #e8f5e9; border: 2px solid #4caf50; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center;">
                            <p style="margin: 0; color: #2e7d32; font-weight: 600;">✅ Wallet Connected</p>
                            <p style="margin: 5px 0 0 0; color: #2e7d32; font-size: 13px; font-family: monospace;">
                                <?php echo esc_html(cardanoPress()->userProfile()->getTrimmedAddress()); ?>
                            </p>
                        </div>
                    <?php else : ?>
                        <div style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 8px; text-align: center;">
                            <p style="margin: 0 0 15px 0; color: #856404; font-weight: 600;">Please connect your wallet to send a tip</p>
                            <button
                                class="cardanopress-wallet-connect"
                                x-data
                                x-on:click="showModal = true"
                                style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 12px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);">
                                Connect Wallet
                            </button>
                        </div>
                        <script>
                            // Hide amount selection until wallet is connected
                            document.getElementById('<?php echo esc_js($widget_id); ?>-step-amount').style.display = 'none';
                        </script>
                    <?php endif; ?>
                </div>

                <!-- Step 2: Select Amount -->
                <div id="<?php echo esc_attr($widget_id); ?>-step-amount" class="tip-step">
                    <h4 style="margin: 0 0 15px 0; color: #374151; font-size: 16px; font-weight: 600;">Select Tip Amount (ADA)</h4>

                    <!-- Preset Amount Buttons -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px;">
                        <?php foreach ($preset_amounts as $amount) : ?>
                            <button class="<?php echo esc_attr($widget_id); ?>-amount-btn" data-amount="<?php echo esc_attr($amount); ?>"
                                    style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border: 2px solid #d1d5db; color: #374151; padding: 12px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; box-sizing: border-box;"
                                    onmouseover="this.style.transform='translateY(-2px)'; this.style.borderColor='#667eea';"
                                    onmouseout="if(!this.classList.contains('selected')) { this.style.transform='translateY(0)'; this.style.borderColor='#d1d5db'; }">
                                ₳<?php echo esc_html($amount); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Custom Amount -->
                    <?php if ($allow_custom) : ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; color: #6b7280; font-size: 14px; font-weight: 600;">Or enter custom amount:</label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6b7280; font-weight: 600;">₳</span>
                                <input type="number" id="<?php echo esc_attr($widget_id); ?>-custom-amount"
                                       min="<?php echo esc_attr($min_amount); ?>"
                                       step="0.01"
                                       placeholder="<?php echo esc_attr($min_amount); ?>"
                                       style="width: 100%; padding: 12px 12px 12px 30px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 16px; font-weight: 600; box-sizing: border-box;">
                            </div>
                            <p style="margin: 5px 0 0 0; color: #9ca3af; font-size: 12px;">Minimum: ₳<?php echo esc_html($min_amount); ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Optional Message -->
                    <?php if ($allow_message) : ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; color: #6b7280; font-size: 14px; font-weight: 600;">Add a message (optional):</label>
                            <textarea id="<?php echo esc_attr($widget_id); ?>-message"
                                      rows="3"
                                      maxlength="250"
                                      placeholder="Your message will be stored on-chain..."
                                      style="width: 100%; padding: 12px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 14px; resize: vertical; box-sizing: border-box;"></textarea>
                            <p style="margin: 5px 0 0 0; color: #9ca3af; font-size: 12px;">Messages are stored as CIP-20 metadata on the blockchain</p>
                        </div>
                    <?php endif; ?>

                    <!-- Send Button -->
                    <?php
                    // Build send button styles
                    $send_btn_style = "color: white; padding: 15px 30px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: all 0.2s; width: 100%;";
                    if ($current_color['is_glass']) {
                        $send_btn_style .= "background: linear-gradient(135deg, rgba(0, 51, 173, 0.3), rgba(0, 212, 255, 0.2)); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border: 1px solid rgba(0, 212, 255, 0.5); box-shadow: 0 4px 16px rgba(0, 212, 255, 0.2);";
                    } else {
                        $send_btn_style .= "background: {$current_color['gradient']}; border: none; box-shadow: 0 4px 12px {$current_color['shadow']};";
                    }
                    ?>
                    <button id="<?php echo esc_attr($widget_id); ?>-send-btn"
                            style="<?php echo esc_attr($send_btn_style); ?>"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0, 212, 255, 0.3)';"
                            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='<?php echo $current_color['is_glass'] ? '0 4px 16px rgba(0, 212, 255, 0.2)' : '0 4px 12px ' . esc_js($current_color['shadow']); ?>';">
                        Send Tip
                    </button>
                </div>

                <!-- Processing/Success Messages -->
                <div id="<?php echo esc_attr($widget_id); ?>-status" style="display: none;"></div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const widgetId = '<?php echo esc_js($widget_id); ?>';
        const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const modal = document.getElementById(widgetId + '-modal');
        const btn = document.getElementById(widgetId + '-btn');
        const closeBtn = document.getElementById(widgetId + '-close');
        const sendBtn = document.getElementById(widgetId + '-send-btn');
        const statusDiv = document.getElementById(widgetId + '-status');
        const amountBtns = document.querySelectorAll('.' + widgetId + '-amount-btn');
        const customAmountInput = document.getElementById(widgetId + '-custom-amount');

        let selectedAmount = null;
        let tipWallet = null; // Store connected wallet info

        // Initialize wallet connection on page load
        async function initializeTipWallet() {
            console.log('=== INITIALIZING TIP WALLET ===');

            const walletName = getCardanoPressWalletName();
            console.log('CardanoPress wallet name:', walletName);

            if (!walletName) {
                console.warn('⚠️ No wallet connected in CardanoPress');
                return;
            }

            // Convert wallet name to lowercase for window.cardano lookup (CardanoPress does this)
            const walletKey = walletName.toLowerCase();
            // Handle Typhon special case
            const cardanoKey = walletKey === 'typhon' ? 'typhoncip30' : walletKey;

            if (!window.cardano || !window.cardano[cardanoKey]) {
                console.error('❌ Wallet not found in window.cardano:', cardanoKey);
                console.log('Available wallets:', window.cardano ? Object.keys(window.cardano) : 'window.cardano not available');
                return;
            }

            try {
                console.log('Enabling wallet API for:', walletName);
                const walletApi = await window.cardano[cardanoKey].enable();
                console.log('✅ Wallet API enabled');

                // Store wallet info
                tipWallet = {
                    name: walletName,
                    api: walletApi
                };

                console.log('✅ Tip wallet initialized successfully!');
                console.log('Wallet:', tipWallet.name);
            } catch (error) {
                console.error('❌ Failed to initialize tip wallet:', error);
            }
        }

        // Call initialization when page loads - with delay to ensure CardanoPress and wallets are ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initializeTipWallet, 2000); // Wait 2 seconds for everything to load
            });
        } else {
            setTimeout(initializeTipWallet, 2000); // Wait 2 seconds for everything to load
        }

        // Open modal
        btn.addEventListener('click', function() {
            modal.style.display = 'flex';
        });

        // Close modal
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        // Close on outside click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Preset amount selection
        amountBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                // Remove selected class from all buttons
                amountBtns.forEach(function(b) {
                    b.classList.remove('selected');
                    b.style.background = 'linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%)';
                    b.style.borderColor = '#d1d5db';
                    b.style.color = '#374151';
                });

                // Add selected class to clicked button
                this.classList.add('selected');
                this.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
                this.style.borderColor = '#3730a3';
                this.style.color = 'white';

                selectedAmount = parseFloat(this.dataset.amount);

                // Clear custom input
                if (customAmountInput) {
                    customAmountInput.value = '';
                }
            });
        });

        // Custom amount input
        if (customAmountInput) {
            customAmountInput.addEventListener('input', function() {
                // Deselect preset buttons
                amountBtns.forEach(function(b) {
                    b.classList.remove('selected');
                    b.style.background = 'linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%)';
                    b.style.borderColor = '#d1d5db';
                    b.style.color = '#374151';
                });

                selectedAmount = parseFloat(this.value) || null;
            });
        }

        // Send tip
        sendBtn.addEventListener('click', function() {
            // Validate amount
            const minAmount = <?php echo esc_js($min_amount); ?>;
            if (!selectedAmount || selectedAmount < minAmount) {
                alert('Please select or enter a valid tip amount (minimum ₳' + minAmount + ')');
                return;
            }

            // Get message if enabled
            const messageInput = document.getElementById(widgetId + '-message');
            const message = messageInput ? messageInput.value.trim() : '';

            // Show processing status
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = '<div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 8px; text-align: center;"><p style="margin: 0; color: #1565c0; font-weight: 600;">⏳ Building transaction...</p></div>';

            // Hide amount selection
            document.getElementById(widgetId + '-step-amount').style.display = 'none';

            // Call AJAX to build and submit transaction
            sendTip(selectedAmount, message);
        });

        // Get CardanoPress connected wallet name from localStorage
        function getCardanoPressWalletName() {
            // CardanoPress stores the connected extension name in localStorage
            const storedName = localStorage.getItem('_x_connectedExtension');
            if (storedName) {
                console.log('CardanoPress connected wallet from localStorage:', storedName);
                return storedName.toLowerCase();
            }
            return null;
        }

        async function sendTip(amount, message) {
            try {
                console.log('=== SEND TIP CALLED ===');

                // Step 1: Check if wallet is initialized
                if (!tipWallet || !tipWallet.api) {
                    console.error('❌ Wallet not initialized! Attempting to re-initialize...');
                    await initializeTipWallet();

                    if (!tipWallet || !tipWallet.api) {
                        throw new Error('Wallet not connected. Please refresh the page and connect your wallet.');
                    }
                }

                console.log('✅ Using wallet:', tipWallet.name);

                statusDiv.innerHTML = '<div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 8px; text-align: center;"><p style="margin: 0; color: #1565c0; font-weight: 600;">📦 Fetching wallet data...</p></div>';

                // Step 2: Get wallet address and UTXOs from the CIP-30 API
                const changeAddressHex = await tipWallet.api.getChangeAddress();
                const utxosHex = await tipWallet.api.getUtxos();

                console.log('Change address (hex):', changeAddressHex);
                console.log('Got', utxosHex.length, 'UTXOs from wallet');

                // Addresses are in hex format from CIP-30 API - send as-is to backend
                const senderAddress = changeAddressHex;
                const changeAddress = changeAddressHex;

                // UTXOs are also in hex format - pass to backend as-is
                const utxos = utxosHex;

                // Step 3: Call WordPress AJAX to build transaction with Anvil
                statusDiv.innerHTML = '<div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 8px; text-align: center;"><p style="margin: 0; color: #1565c0; font-weight: 600;">🔨 Building transaction with Anvil API...</p></div>';

                const buildResponse = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'cardano_process_tip',
                        amount: amount,
                        message: message,
                        sender_address: senderAddress,
                        change_address: changeAddress,
                        utxos: JSON.stringify(utxos)
                    })
                });

                const buildData = await buildResponse.json();

                if (!buildData.success) {
                    throw new Error(buildData.data.message || 'Failed to build transaction');
                }

                const cborHex = buildData.data.cborHex;

                // Step 4: Sign transaction with wallet
                statusDiv.innerHTML = '<div style="background: #fff8e1; border: 2px solid #ffa000; padding: 20px; border-radius: 8px; text-align: center;"><p style="margin: 0; color: #e65100; font-weight: 600;">✍️ Please sign the transaction in your wallet...</p></div>';

                console.log('Requesting signature for transaction...');
                const witnessSet = await tipWallet.api.signTx(cborHex, true);
                console.log('Transaction signed successfully');

                // Step 5: Submit transaction to Anvil API (with unsigned tx + witness)
                statusDiv.innerHTML = '<div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 8px; text-align: center;"><p style="margin: 0; color: #1565c0; font-weight: 600;">📡 Submitting to blockchain...</p></div>';

                console.log('Submitting transaction to blockchain via Anvil...');

                // Call backend to submit via Anvil API
                const submitResponse = await fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'cardano_submit_tip',
                        transaction: cborHex,
                        signature: witnessSet
                    })
                });

                const submitData = await submitResponse.json();

                if (!submitData.success) {
                    throw new Error(submitData.data.message || 'Transaction submission failed');
                }

                const txHash = submitData.data.txHash;
                console.log('Transaction submitted successfully:', txHash);

                // Success!
                const explorerUrl = buildData.data.network === 'mainnet'
                    ? 'https://cardanoscan.io/transaction/' + txHash
                    : 'https://preprod.cardanoscan.io/transaction/' + txHash;

                statusDiv.innerHTML = '<div style="background: #e8f5e9; border: 2px solid #4caf50; padding: 25px; border-radius: 8px; text-align: center;">' +
                    '<h4 style="margin: 0 0 10px 0; color: #2e7d32; font-size: 20px;">✅ Tip Sent Successfully!</h4>' +
                    '<p style="margin: 0 0 15px 0; color: #2e7d32;"><?php echo esc_js(!empty($settings['thank_you_message']) ? $settings['thank_you_message'] : 'Thank you for your generous tip!'); ?></p>' +
                    '<p style="margin: 0; font-size: 13px;"><strong>Transaction ID:</strong><br>' +
                    '<a href="' + explorerUrl + '" target="_blank" style="color: #2e7d32; word-break: break-all; text-decoration: underline;">' + txHash + '</a></p>' +
                    '</div>';

            } catch (error) {
                console.error('Tip error:', error);
                statusDiv.innerHTML = '<div style="background: #ffebee; border: 2px solid #f44336; padding: 20px; border-radius: 8px; text-align: center;">' +
                    '<p style="margin: 0; color: #c62828; font-weight: 600;">❌ Transaction Failed</p>' +
                    '<p style="margin: 10px 0 0 0; color: #c62828; font-size: 14px;">' + error.message + '</p>' +
                    '</div>';

                // Show amount selection again so user can retry
                setTimeout(function() {
                    document.getElementById(widgetId + '-step-amount').style.display = 'block';
                    statusDiv.style.display = 'none';
                }, 5000);
            }
        }
    })();
    </script>

    <?php
    return ob_get_clean();
}

/**
 * Convert hex address to Bech32 using Anvil API
 */
function cardano_hex_to_bech32($hex_address, $network = 'preprod') {
    // If it's already Bech32 (starts with addr1 or addr_test1), return as-is
    if (strpos($hex_address, 'addr') === 0) {
        return $hex_address;
    }

    // Get Anvil API key
    $api_key = $network === 'mainnet'
        ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
        : get_option('cardano_wallet_test_anvil_key_preprod', '');

    if (empty($api_key)) {
        error_log('Anvil API key not configured for address conversion');
        return $hex_address; // Return original if no API key
    }

    // Determine API URL based on network
    $api_base_url = $network === 'mainnet'
        ? 'https://prod.api.ada-anvil.app/v2/services'
        : 'https://preprod.api.ada-anvil.app/v2/services';

    // Call Anvil API to parse address
    $response = wp_remote_post($api_base_url . '/utils/addresses/parse', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Api-Key' => $api_key
        ),
        'body' => json_encode(array('address' => $hex_address)),
        'timeout' => 10
    ));

    if (is_wp_error($response)) {
        error_log('Anvil address conversion failed: ' . $response->get_error_message());
        return $hex_address; // Return original on error
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Try different response formats
    if (isset($body['address'])) {
        return $body['address'];
    } elseif (isset($body['bech32Address'])) {
        return $body['bech32Address'];
    } elseif (isset($body['parsed']['address'])) {
        return $body['parsed']['address'];
    }

    error_log('No Bech32 address found in Anvil response, returning original');
    return $hex_address;
}

/**
 * AJAX Handler for submitting tip transactions to Anvil
 */
add_action('wp_ajax_cardano_submit_tip', 'cardano_submit_tip_ajax');
add_action('wp_ajax_nopriv_cardano_submit_tip', 'cardano_submit_tip_ajax');

function cardano_submit_tip_ajax() {
    // Get widget settings for network
    $settings = get_option('cardano_tip_widget_settings', array());
    $network = !empty($settings['network']) ? $settings['network'] : 'preprod';

    // Get Anvil API key
    $api_key = $network === 'mainnet'
        ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
        : get_option('cardano_wallet_test_anvil_key_preprod', '');

    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'Anvil API key not configured'));
        return;
    }

    // Get transaction and signature from request
    // Use wp_unslash and sanitize hex strings (don't use sanitize_text_field - it truncates!)
    $transaction = isset($_POST['transaction']) ? preg_replace('/[^a-fA-F0-9]/', '', wp_unslash($_POST['transaction'])) : '';
    $signature = isset($_POST['signature']) ? preg_replace('/[^a-fA-F0-9]/', '', wp_unslash($_POST['signature'])) : '';

    if (empty($transaction) || empty($signature)) {
        wp_send_json_error(array('message' => 'Missing transaction or signature'));
        return;
    }

    // Prepare submission data for Anvil API
    $submit_data = array(
        'transaction' => $transaction,
        'signatures' => array($signature)
    );

    // Determine API URL
    $api_url = $network === 'mainnet'
        ? 'https://prod.api.ada-anvil.app/v2/services/transactions/submit'
        : 'https://preprod.api.ada-anvil.app/v2/services/transactions/submit';

    // Submit to Anvil API
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Api-Key' => $api_key
        ),
        'body' => json_encode($submit_data),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Submission error: ' . $response->get_error_message()));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $submit_response = json_decode($body, true);

    if ($status_code !== 200) {
        wp_send_json_error(array(
            'message' => 'Transaction submission failed',
            'details' => $submit_response,
            'status_code' => $status_code
        ));
        return;
    }

    // Extract transaction hash from response
    $tx_hash = $submit_response['txHash'] ?? $submit_response['hash'] ?? $submit_response['transactionHash'] ?? '';

    if (empty($tx_hash)) {
        wp_send_json_error(array(
            'message' => 'No transaction hash returned',
            'response' => $submit_response
        ));
        return;
    }

    // Success!
    wp_send_json_success(array(
        'txHash' => $tx_hash,
        'message' => 'Tip sent successfully!'
    ));
}

/**
 * AJAX Handler for processing tip widget transactions
 */
add_action('wp_ajax_cardano_process_tip', 'cardano_process_tip_ajax');
add_action('wp_ajax_nopriv_cardano_process_tip', 'cardano_process_tip_ajax');

function cardano_process_tip_ajax() {
    // Get widget settings
    $settings = get_option('cardano_tip_widget_settings', array());

    if (empty($settings['enabled']) || empty($settings['recipient_address'])) {
        wp_send_json_error(array('message' => 'Tip widget is not configured properly.'));
        return;
    }

    // Get request data - USER's wallet address
    $amount = (float) $_POST['amount'];
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
    $change_address = isset($_POST['change_address']) ? wp_unslash($_POST['change_address']) : '';
    $utxos = !empty($_POST['utxos']) ? json_decode(wp_unslash($_POST['utxos']), true) : [];

    // Validate
    if ($amount < 1) {
        wp_send_json_error(array('message' => 'Invalid tip amount. Minimum is 1 ADA.'));
        return;
    }

    if (empty($change_address)) {
        wp_send_json_error(array('message' => 'No wallet address provided'));
        return;
    }

    $recipient_address = $settings['recipient_address'];
    $network = !empty($settings['network']) ? $settings['network'] : 'preprod';

    // Get Anvil API key
    $api_key = $network === 'mainnet'
        ? get_option('cardano_wallet_test_anvil_key_mainnet', '')
        : get_option('cardano_wallet_test_anvil_key_preprod', '');

    if (empty($api_key)) {
        wp_send_json_error(array('message' => 'Anvil API key not configured for ' . $network . ' network.'));
        return;
    }

    // Determine API URL based on network
    $api_url = $network === 'mainnet'
        ? 'https://prod.api.ada-anvil.app/v2/services/transactions/build'
        : 'https://preprod.api.ada-anvil.app/v2/services/transactions/build';

    // Build transaction request payload - USER's address sends to recipient
    $payload = array(
        'changeAddress' => $change_address,  // USER's wallet address (Bech32 or hex from CIP-30)
        'outputs' => array(
            array(
                'address' => $recipient_address,  // Where the tip goes (Bech32 from settings)
                'lovelace' => (int)($amount * 1_000_000)
            )
        )
    );

    // CIP-20 message (Anvil does the right thing) - use 'message' not 'metadata'
    if (!empty($message) && !empty($settings['allow_message'])) {
        $payload['message'] = $message;  // Let Anvil handle CIP-20 chunking/splitting automatically
    }

    // UTXOs: strictly required on mainnet, safe to include on preprod as well
    if (!empty($utxos)) {
        $payload['utxos'] = $utxos;
    }

    // Call Anvil API to build transaction
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'X-Api-Key' => $api_key
        ),
        'body' => json_encode($payload),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'API Error: ' . $response->get_error_message()));
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($status_code !== 200) {
        wp_send_json_error(array(
            'message' => 'Transaction build failed',
            'details' => $body,
            'status_code' => $status_code
        ));
        return;
    }

    $build_response = json_decode($body, true);

    // Get unsigned transaction CBOR - prefer 'complete' for simple payments
    $tx_cbor = $build_response['complete'] ?? $build_response['stripped'] ?? $build_response['transaction'] ?? '';

    if (empty($tx_cbor)) {
        wp_send_json_error(array(
            'message' => 'Invalid response from Anvil API - no transaction hex returned',
            'available_keys' => array_keys($build_response)
        ));
        return;
    }

    // Return unsigned transaction for USER to sign with their wallet
    wp_send_json_success(array(
        'cborHex' => $tx_cbor,
        'message' => 'Transaction built successfully! Ready for signing.',
        'network' => $network
    ));
}
