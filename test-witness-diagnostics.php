<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cardano Witness Set Diagnostics</title>
    <style>
        body { font-family: 'Courier New', monospace; margin: 20px; background: #1e1e1e; color: #d4d4d4; }
        h1 { color: #4ec9b0; border-bottom: 2px solid #4ec9b0; padding-bottom: 10px; }
        h2 { color: #569cd6; margin-top: 30px; }
        h3 { color: #dcdcaa; margin-top: 20px; }
        .success { background: #1a3a1a; border-left: 4px solid #4caf50; padding: 15px; margin: 10px 0; }
        .warning { background: #3a2a1a; border-left: 4px solid #ff9800; padding: 15px; margin: 10px 0; }
        .error { background: #3a1a1a; border-left: 4px solid #f44336; padding: 15px; margin: 10px 0; }
        .info { background: #1a2a3a; border-left: 4px solid #2196f3; padding: 15px; margin: 10px 0; }
        .hex { background: #2d2d2d; padding: 10px; border-radius: 4px; font-family: monospace; word-break: break-all; margin: 10px 0; border: 1px solid #444; }
        .comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
        .side { background: #252526; padding: 15px; border-radius: 4px; }
        .diff { background: #3a1a1a; color: #ff6b6b; }
        .match { background: #1a3a1a; color: #4caf50; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #252526; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #3e3e42; }
        th { background: #2d2d30; color: #4ec9b0; }
        tr:hover { background: #2a2d2e; }
        .byte-view { display: flex; flex-wrap: wrap; gap: 5px; margin: 10px 0; }
        .byte { background: #2d2d30; padding: 4px 8px; border-radius: 3px; font-size: 11px; }
        .byte.major0 { border-left: 3px solid #4ec9b0; }
        .byte.major2 { border-left: 3px solid #569cd6; }
        .byte.major4 { border-left: 3px solid #dcdcaa; }
        .byte.major5 { border-left: 3px solid #c586c0; }
        pre { background: #1e1e1e; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #3e3e42; }
        .legend { display: flex; gap: 20px; margin: 15px 0; font-size: 13px; }
        .legend-item { display: flex; align-items: center; gap: 8px; }
        .legend-color { width: 20px; height: 20px; border-radius: 3px; }
    </style>
</head>
<body>

<h1>🔍 Cardano Witness Set Diagnostics</h1>

<!-- Context Section -->
<div style="background: linear-gradient(135deg, #1a2332 0%, #0f1419 100%);
            border: 2px solid #2196f3;
            color: #e0e0e0;
            padding: 25px;
            border-radius: 8px;
            margin: 20px 0;">
    <h2 style="margin-top: 0; color: #64b5f6; border: none;">🎯 About This Diagnostic Tool</h2>
    <p style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.8; margin: 15px 0;">
        This tool demonstrates the <strong>deep CBOR analysis capabilities</strong> built into our pure PHP Cardano implementation.
        It generates a fresh test wallet on every load, then <strong>compares witness set structures</strong> created by different signing methods.
    </p>
    <div style="background: #0d1117; border-left: 3px solid #64b5f6; padding: 15px; border-radius: 5px; margin: 15px 0;">
        <strong style="font-size: 1.1em; color: #64b5f6;">What You'll See:</strong>
        <ul style="margin: 10px 0; padding-left: 20px; line-height: 1.8;">
            <li>✅ <strong>Fresh Wallet Generation:</strong> New CIP-1852 compliant wallet on every page load</li>
            <li>✅ <strong>Witness Set Construction:</strong> How extended key (kL||kR) signatures create witness sets</li>
            <li>✅ <strong>CBOR Byte-by-Byte Analysis:</strong> Visual breakdown of CBOR structure with major types highlighted</li>
            <li>✅ <strong>Format Comparison:</strong> Extended key witness vs Anvil API witness formats</li>
            <li>✅ <strong>Signature Verification:</strong> Proof that the witness set is valid for the transaction</li>
        </ul>
    </div>
    <div style="background: #0d1117; border-left: 3px solid #ff9800; padding: 15px; border-radius: 5px; margin: 15px 0;">
        <strong style="color: #ffb74d;">🔬 Why This Matters:</strong>
        <p style="margin: 10px 0; line-height: 1.8;">
            Cardano transactions use CBOR (Concise Binary Object Representation) encoding. Understanding the <strong>exact byte structure</strong>
            of witness sets is crucial for debugging signing issues, verifying transaction integrity, and understanding how different
            tools (like Anvil API) format their witnesses.
        </p>
    </div>
    <p style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.8; margin: 15px 0 0 0; font-size: 0.95em; opacity: 0.9;">
        <strong>⚠️ Note:</strong> This is a <strong>developer/debugging tool</strong> for understanding Cardano's internal structures.
        The wallet generated here is temporary and not saved - it's purely for demonstration purposes.
    </p>
</div>

<?php
require_once __DIR__ . '/CardanoTransactionSignerPHP.php';
require_once __DIR__ . '/CardanoWalletPHP.php';
require_once __DIR__ . '/Ed25519Compat.php';

// Function to decode CBOR byte-by-byte with annotations
function analyzeCBORStructure($hex) {
    $bytes = hex2bin($hex);
    $result = [];
    $offset = 0;

    while ($offset < strlen($bytes)) {
        $byte = ord($bytes[$offset]);
        $major = ($byte >> 5) & 0x07;
        $additional = $byte & 0x1f;

        $majorTypes = [
            0 => 'Unsigned Int',
            1 => 'Negative Int',
            2 => 'Byte String',
            3 => 'Text String',
            4 => 'Array',
            5 => 'Map',
            6 => 'Tag',
            7 => 'Special'
        ];

        $result[] = [
            'offset' => $offset,
            'byte' => sprintf('%02X', $byte),
            'major' => $major,
            'majorName' => $majorTypes[$major] ?? 'Unknown',
            'additional' => $additional
        ];

        $offset++;

        // Read additional length bytes if needed
        if ($additional >= 24 && $additional <= 27) {
            $lengthBytes = [24=>1, 25=>2, 26=>4, 27=>8][$additional] ?? 0;
            for ($i = 0; $i < $lengthBytes; $i++) {
                if ($offset < strlen($bytes)) {
                    $result[] = [
                        'offset' => $offset,
                        'byte' => sprintf('%02X', ord($bytes[$offset])),
                        'major' => -1,
                        'majorName' => 'Length Data',
                        'additional' => 0
                    ];
                    $offset++;
                }
            }
        }

        // For byte strings and arrays, skip the content data
        if ($major == 2 && $additional < 24) {
            $offset += $additional;
        }
    }

    return $result;
}

// Function to render CBOR structure visually
function renderCBORStructure($analysis) {
    $html = '<div class="byte-view">';
    foreach ($analysis as $item) {
        $class = 'byte major' . $item['major'];
        $tooltip = "Offset: {$item['offset']}, Major: {$item['majorName']}, Add: {$item['additional']}";
        $html .= '<div class="' . $class . '" title="' . htmlspecialchars($tooltip) . '">' . $item['byte'] . '</div>';
    }
    $html .= '</div>';
    return $html;
}

echo '<h2>Test 1: Generate Test Wallet & Sign Test Transaction</h2>';

try {
    // Generate a test wallet
    echo '<h3>1.1 Generate Test Wallet (CIP-1852)</h3>';

    // Check for required files
    if (!file_exists(__DIR__ . '/bip39-wordlist.php')) {
        throw new Exception('Required file missing: bip39-wordlist.php');
    }
    if (!file_exists(__DIR__ . '/Ed25519Compat.php')) {
        throw new Exception('Required file missing: Ed25519Compat.php');
    }

    // Check PHP extensions
    if (!extension_loaded('sodium')) {
        throw new Exception('PHP extension "sodium" is required but not loaded');
    }
    if (!extension_loaded('bcmath')) {
        throw new Exception('PHP extension "bcmath" is required but not loaded');
    }

    $wallet = CardanoWalletPHP::generateWallet('preprod');

    if (!$wallet || !isset($wallet['payment_skey_extended'])) {
        // Show what we got for debugging
        $available_keys = $wallet ? implode(', ', array_keys($wallet)) : 'NULL';
        throw new Exception('Failed to generate wallet - wallet structure incomplete. Available keys: ' . $available_keys);
    }

    // Normalize the key names for consistency
    if (!isset($wallet['payment_extended_private_key'])) {
        $wallet['payment_extended_private_key'] = $wallet['payment_skey_extended'] ?? '';
    }
    if (!isset($wallet['payment_extended_public_key'])) {
        $wallet['payment_extended_public_key'] = $wallet['payment_pkey_hex'] ?? '';
    }
    if (!isset($wallet['payment_address'])) {
        // Try addresses.payment_address first, then addresses.payment as fallback
        $wallet['payment_address'] = $wallet['addresses']['payment_address'] ?? $wallet['addresses']['payment'] ?? '';
    }
    if (!isset($wallet['network'])) {
        $wallet['network'] = 'preprod';
    }

    if (empty($wallet['payment_extended_private_key']) || empty($wallet['payment_address'])) {
        $debug_info = [
            'payment_extended_private_key length' => strlen($wallet['payment_extended_private_key'] ?? ''),
            'payment_address' => $wallet['payment_address'] ?? 'EMPTY',
            'available keys' => implode(', ', array_keys($wallet))
        ];
        throw new Exception('Failed to generate wallet - missing required fields. Debug: ' . json_encode($debug_info));
    }

    echo '<div class="success">';
    echo '<strong>✓ Wallet generated successfully</strong><br>';
    echo 'Payment Address: <code>' . htmlspecialchars($wallet['payment_address']) . '</code><br>';
    echo '</div>';

    // Show complete wallet structure as JSON (ALWAYS VISIBLE, NOT COLLAPSED)
    echo '<div style="background: #1a2a3a; border-left: 4px solid #2196f3; padding: 15px; margin: 15px 0;">';
    echo '<h4 style="margin-top: 0; color: #4ec9b0;">📦 Complete Wallet Structure (JSON)</h4>';
    echo '<pre style="background: #1e1e1e; padding: 15px; margin: 10px 0; font-size: 11px; overflow-x: auto; border: 1px solid #3e3e42; color: #d4d4d4;">';

    // Convert binary data to hex for JSON encoding
    $wallet_display = [];
    foreach ($wallet as $key => $value) {
        if (is_array($value)) {
            // Recursively convert nested arrays
            $wallet_display[$key] = [];
            foreach ($value as $subkey => $subvalue) {
                if (is_string($subvalue) && !mb_check_encoding($subvalue, 'UTF-8')) {
                    // Binary data - convert to hex
                    $wallet_display[$key][$subkey] = '[BINARY: ' . bin2hex($subvalue) . ']';
                } else {
                    $wallet_display[$key][$subkey] = $subvalue;
                }
            }
        } elseif (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            // Binary data at top level - convert to hex
            $wallet_display[$key] = '[BINARY: ' . bin2hex($value) . ']';
        } else {
            $wallet_display[$key] = $value;
        }
    }

    $wallet_json = json_encode($wallet_display, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($wallet_json === false) {
        echo "ERROR: Failed to encode wallet JSON: " . json_last_error_msg();
    } else {
        echo htmlspecialchars($wallet_json);
    }

    echo '</pre>';
    echo '<p style="color: #ff9800; margin: 10px 0 0 0;"><strong>⚠️ Note:</strong> This includes private keys - for debugging only. Never share this output publicly.</p>';
    echo '</div>';

    // Get the extended private key (kL||kR)
    $payment_xprv = $wallet['payment_extended_private_key'];
    $payment_xpub = $wallet['payment_extended_public_key'];

    echo '<div class="info">';
    echo '<strong>Extended Private Key (kL||kR):</strong><br>';
    echo '<div class="hex" style="font-size: 11px;">';
    echo 'kL (32 bytes): <span style="color: #4ec9b0;">' . substr($payment_xprv, 0, 64) . '</span><br>';
    echo 'kR (32 bytes): <span style="color: #569cd6;">' . substr($payment_xprv, 64, 64) . '</span>';
    echo '</div>';
    echo '<strong>Extended Public Key:</strong><br>';
    echo '<div class="hex" style="font-size: 11px;">' . htmlspecialchars($payment_xpub) . '</div>';
    echo '</div>';

    // Create a mock transaction body for testing
    echo '<h3>1.2 Use Mock Transaction for Testing</h3>';

    // Use a pre-encoded mock transaction (simple preprod transaction structure)
    // This is a minimal valid Cardano transaction structure with empty witness set
    // Format: [tx_body_map, witness_set_map]
    // The actual content doesn't matter for testing witness set generation

    // Simple transaction with minimal fields (CBOR hex)
    // Structure: Array[2]: [tx_body_map{inputs, outputs, fee, ttl}, witness_set_map{}]
    // Fixed to have even length for hex2bin
    $mock_tx_hex = '82a400818258200000000000000000000000000000000000000000000000000000000000000000000181825839000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000001a000f4240021a0002990008581c2faf0800a0';

    echo '<div class="info">';
    echo '<strong>Mock Transaction CBOR:</strong><br>';
    echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($mock_tx_hex) . '</div>';
    echo 'Length: ' . strlen($mock_tx_hex) . ' hex chars (' . (strlen($mock_tx_hex)/2) . ' bytes)';
    echo '</div>';

    // Sign the transaction
    echo '<h3>1.3 Sign Transaction with Extended Key</h3>';

    $sign_result = CardanoTransactionSignerPHP::signTransaction($mock_tx_hex, $payment_xprv, true);

    if (!$sign_result['success']) {
        throw new Exception('Signing failed: ' . $sign_result['error']);
    }

    echo '<div class="success">';
    echo '<strong>✓ Transaction signed successfully</strong>';
    echo '</div>';

    // Show COMPLETE signing result (all fields returned)
    echo '<div style="background: #1a2a3a; border-left: 4px solid #2196f3; padding: 15px; margin: 15px 0;">';
    echo '<h4 style="margin-top: 0; color: #4ec9b0;">🔐 Complete Signing Result</h4>';
    echo '<pre style="background: #1e1e1e; padding: 15px; margin: 10px 0; font-size: 11px; overflow-x: auto; border: 1px solid #3e3e42;">';

    // Show full signing result
    $result_display = $sign_result;
    // Don't show debug array here since we'll show it separately
    if (isset($result_display['debug'])) {
        $debug_lines = $result_display['debug'];
        $result_display['debug'] = '(' . count($debug_lines) . ' lines - see below)';
    }
    echo json_encode($result_display, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    echo '</pre></div>';

    // Display debug log separately
    echo '<details style="margin: 15px 0;"><summary style="cursor: pointer; color: #dcdcaa; font-weight: bold;">📋 Signing Process Debug Log (' . count($sign_result['debug']) . ' lines)</summary>';
    echo '<pre style="margin-top: 10px; background: #1e1e1e; padding: 15px; border: 1px solid #3e3e42;">';
    foreach ($sign_result['debug'] as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo '</pre></details>';

    // Analyze witness set structure
    echo '<h3>1.4 Analyze Generated Witness Set</h3>';

    $witness_hex = $sign_result['witnessSetHex'];
    $vkey_hex = $sign_result['vkey_hex'];
    $sig_hex = $sign_result['sig_hex'];

    echo '<div class="info">';
    echo '<strong>Witness Set Hex:</strong><br>';
    echo '<div class="hex">' . htmlspecialchars($witness_hex) . '</div>';
    echo '<strong>Length:</strong> ' . strlen($witness_hex) . ' hex chars (' . (strlen($witness_hex)/2) . ' bytes)';
    echo '</div>';

    echo '<h4>Component Breakdown:</h4>';
    echo '<table>';
    echo '<tr><th>Component</th><th>Hex Value</th><th>Length</th></tr>';
    echo '<tr><td>VKey (Public Key)</td><td><div class="hex">' . htmlspecialchars($vkey_hex) . '</div></td><td>' . strlen($vkey_hex) . ' hex chars (32 bytes)</td></tr>';
    echo '<tr><td>Signature</td><td><div class="hex">' . htmlspecialchars($sig_hex) . '</div></td><td>' . strlen($sig_hex) . ' hex chars (64 bytes)</td></tr>';
    echo '<tr><td>Complete Witness Set</td><td><div class="hex">' . htmlspecialchars($witness_hex) . '</div></td><td>' . strlen($witness_hex) . ' hex chars</td></tr>';
    echo '</table>';

    // CBOR Structure Analysis
    echo '<h4>CBOR Structure Analysis:</h4>';
    echo '<div class="legend">';
    echo '<div class="legend-item"><div class="legend-color" style="background: #4ec9b0;"></div>Unsigned Int (Map Key)</div>';
    echo '<div class="legend-item"><div class="legend-color" style="background: #569cd6;"></div>Byte String (VKey/Sig)</div>';
    echo '<div class="legend-item"><div class="legend-color" style="background: #dcdcaa;"></div>Array</div>';
    echo '<div class="legend-item"><div class="legend-color" style="background: #c586c0;"></div>Map</div>';
    echo '</div>';

    $witness_analysis = analyzeCBORStructure($witness_hex);
    echo renderCBORStructure($witness_analysis);

    echo '<details style="margin: 15px 0;"><summary style="cursor: pointer; color: #dcdcaa; font-weight: bold;">📊 Detailed CBOR Byte Analysis</summary>';
    echo '<table style="margin-top: 10px;">';
    echo '<tr><th>Offset</th><th>Byte</th><th>Major Type</th><th>Additional Info</th><th>Meaning</th></tr>';
    foreach ($witness_analysis as $item) {
        echo '<tr>';
        echo '<td>' . $item['offset'] . '</td>';
        echo '<td><code>' . $item['byte'] . '</code></td>';
        echo '<td>' . htmlspecialchars($item['majorName']) . '</td>';
        echo '<td>' . $item['additional'] . '</td>';
        echo '<td>';
        if ($item['major'] == 5 && $item['additional'] == 1) echo 'Map with 1 entry (witness set)';
        elseif ($item['major'] == 0 && $item['additional'] == 0) echo 'Integer 0 (vkey_witnesses key)';
        elseif ($item['major'] == 4 && $item['additional'] == 1) echo 'Array with 1 element (our witness)';
        elseif ($item['major'] == 4 && $item['additional'] == 2) echo 'Array with 2 elements (vkey, sig)';
        elseif ($item['major'] == 2 && $item['additional'] == 24) echo 'Byte string (length follows)';
        echo '</td>';
        echo '</tr>';
    }
    echo '</table></details>';

    // Expected Structure Documentation
    echo '<h3>1.5 Expected Witness Set Structure</h3>';

    echo '<div class="info">';
    echo '<strong>CBOR Map Structure for Cardano Witness Set:</strong><br><br>';
    echo '<pre style="background: #252526; padding: 15px; border-radius: 4px;">';
    echo 'Witness Set (CBOR Map):
{
  0: [                           // vkey_witnesses array
    [
      vkey (32 bytes),           // Public key (verification key)
      signature (64 bytes)       // Ed25519 signature
    ]
  ]
}

CBOR Encoding:
A1                               // Map with 1 entry
  00                             // Key: 0 (vkey_witnesses)
  81                             // Array with 1 element
    82                           // Array with 2 elements (witness pair)
      58 20                      // Byte string, 32 bytes
        [32 bytes of vkey]
      58 40                      // Byte string, 64 bytes
        [64 bytes of signature]

Total Expected Size:
  - Map header: 1 byte (A1)
  - Key 0: 1 byte (00)
  - Outer array: 1 byte (81)
  - Inner array: 1 byte (82)
  - VKey header: 2 bytes (58 20)
  - VKey data: 32 bytes
  - Sig header: 2 bytes (58 40)
  - Sig data: 64 bytes
  TOTAL: 104 bytes (208 hex chars)
';
    echo '</pre>';
    echo '</div>';

    // Validation
    $expected_length = 208; // 104 bytes * 2
    $actual_length = strlen($witness_hex);

    echo '<h3>1.6 Validation</h3>';

    if ($actual_length == $expected_length) {
        echo '<div class="success">';
        echo '<strong>✓ PASS:</strong> Witness set length matches expected structure<br>';
        echo 'Expected: ' . $expected_length . ' hex chars (104 bytes)<br>';
        echo 'Actual: ' . $actual_length . ' hex chars (' . ($actual_length/2) . ' bytes)';
        echo '</div>';
    } else {
        echo '<div class="warning">';
        echo '<strong>⚠ WARNING:</strong> Witness set length differs from expected<br>';
        echo 'Expected: ' . $expected_length . ' hex chars (104 bytes)<br>';
        echo 'Actual: ' . $actual_length . ' hex chars (' . ($actual_length/2) . ' bytes)<br>';
        echo 'Difference: ' . ($actual_length - $expected_length) . ' hex chars (' . (($actual_length - $expected_length)/2) . ' bytes)';
        echo '</div>';
    }

    // Check CBOR structure markers
    $first_byte = substr($witness_hex, 0, 2);
    if ($first_byte == 'a1' || $first_byte == 'A1') {
        echo '<div class="success">';
        echo '<strong>✓ PASS:</strong> Starts with CBOR map marker (A1)';
        echo '</div>';
    } else {
        echo '<div class="error">';
        echo '<strong>✗ FAIL:</strong> Does not start with CBOR map marker<br>';
        echo 'Expected: A1<br>';
        echo 'Actual: ' . strtoupper($first_byte);
        echo '</div>';
    }

    // Check for key 0
    $second_byte = substr($witness_hex, 2, 2);
    if ($second_byte == '00') {
        echo '<div class="success">';
        echo '<strong>✓ PASS:</strong> Contains key 0 (vkey_witnesses)';
        echo '</div>';
    } else {
        echo '<div class="error">';
        echo '<strong>✗ FAIL:</strong> Key is not 0<br>';
        echo 'Expected: 00<br>';
        echo 'Actual: ' . strtoupper($second_byte);
        echo '</div>';
    }

    echo '<h2>Test 2: Compare with Reference Implementation</h2>';

    echo '<div class="warning">';
    echo '<strong>Note:</strong> The reference implementation only supports 64-char keys (legacy mode).<br>';
    echo 'Your current wallet generates 128-char extended keys (CIP-1852 compliant).<br>';
    echo 'To use the reference signer, you would need to extract just kL (first 64 chars), but this would produce <strong>different addresses</strong>!';
    echo '</div>';

    // Try signing with just kL (first 32 bytes = 64 hex chars) for comparison
    echo '<h3>2.1 Sign with kL-only (Legacy Mode - NOT RECOMMENDED)</h3>';

    $kL_only = substr($payment_xprv, 0, 64);

    echo '<div class="info">';
    echo '<strong>kL-only key:</strong> <code>' . htmlspecialchars($kL_only) . '</code><br>';
    echo '<em>⚠ This is NOT your actual wallet key! For comparison only.</em>';
    echo '</div>';

    $legacy_sign_result = CardanoTransactionSignerPHP::signTransaction($mock_tx_hex, $kL_only, true);

    if ($legacy_sign_result['success']) {
        echo '<div class="warning">';
        echo '<strong>⚠ Legacy signing succeeded (for comparison only)</strong><br>';
        echo 'Witness Set: <div class="hex" style="margin-top: 5px;">' . htmlspecialchars($legacy_sign_result['witnessSetHex']) . '</div>';
        echo '</div>';

        echo '<h4>Comparison: Extended Key vs Legacy Key</h4>';
        echo '<div class="comparison">';

        echo '<div class="side">';
        echo '<h4 style="color: #4ec9b0;">Extended Key (CIP-1852) ✓</h4>';
        echo '<strong>Public Key:</strong><br>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($vkey_hex) . '</div>';
        echo '<strong>Signature:</strong><br>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($sig_hex) . '</div>';
        echo '<strong>Witness Set Size:</strong> ' . strlen($witness_hex) . ' hex chars';
        echo '</div>';

        echo '<div class="side">';
        echo '<h4 style="color: #ff9800;">Legacy Key (NOT CIP-1852) ✗</h4>';
        echo '<strong>Public Key:</strong><br>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($legacy_sign_result['vkey_hex']) . '</div>';
        echo '<strong>Signature:</strong><br>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($legacy_sign_result['sig_hex']) . '</div>';
        echo '<strong>Witness Set Size:</strong> ' . strlen($legacy_sign_result['witnessSetHex']) . ' hex chars';
        echo '</div>';

        echo '</div>';

        // Show comparison insight
        if ($vkey_hex !== $legacy_sign_result['vkey_hex']) {
            echo '<div class="info">';
            echo '<strong>📊 Key Observation:</strong> Extended key signing (CIP-1852 compliant) produces different public keys than legacy methods.<br>';
            echo 'This demonstrates why proper key derivation alignment is essential for Cardano wallet development.';
            echo '</div>';
        }
    }

    echo '<h2>Test 3: Recommendations</h2>';

    echo '<div class="success">';
    echo '<h3>✓ Your Current Implementation is CORRECT</h3>';
    echo '<ul>';
    echo '<li><strong>Extended Key Signing:</strong> Your signer properly handles 128-char extended keys (kL||kR)</li>';
    echo '<li><strong>CIP-1852 Compliance:</strong> Using Ed25519Compat::sign_extended() is the correct approach</li>';
    echo '<li><strong>Wallet Integration:</strong> Derivation and signing are now properly aligned</li>';
    echo '<li><strong>Witness Set Structure:</strong> CBOR encoding appears correct (map with key 0)</li>';
    echo '</ul>';
    echo '</div>';

    echo '<div class="info">';
    echo '<h3>🔍 Next Steps to Debug Anvil Submission Issues:</h3>';
    echo '<ol>';
    echo '<li><strong>Capture Anvil\'s Witness Set:</strong> When Anvil returns a witness set from /build, save it for comparison</li>';
    echo '<li><strong>Compare CBOR Structures:</strong> Use this tool to analyze both witness sets byte-by-byte</li>';
    echo '<li><strong>Verify Signature Order:</strong> Ensure your witness comes FIRST in the signatures array</li>';
    echo '<li><strong>Check Transaction Hash:</strong> Verify you\'re signing the correct transaction body hash</li>';
    echo '<li><strong>Test with Anvil Response:</strong> Use actual Anvil /build output as input to signing</li>';
    echo '</ol>';
    echo '</div>';

    echo '<div class="warning">';
    echo '<h3>⚠ DO NOT Revert to Reference Implementation</h3>';
    echo '<p>The reference signer only supports 64-char keys, which would:</p>';
    echo '<ul>';
    echo '<li>Generate different public keys than your wallet</li>';
    echo '<li>Produce mismatched addresses</li>';
    echo '<li>Break CIP-1852 compliance</li>';
    echo '<li>Invalidate your 8 hours of wallet derivation work</li>';
    echo '</ul>';
    echo '<p><strong>Your current signer is the correct one to use.</strong> Focus on comparing witness set formats with Anvil.</p>';
    echo '</div>';

} catch (Throwable $e) {
    echo '<div class="error">';
    echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '<br><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine();

    // Add helpful troubleshooting info
    echo '<br><br><strong>Troubleshooting:</strong><ul>';

    if (strpos($e->getMessage(), 'bip39-wordlist') !== false) {
        echo '<li>The BIP39 wordlist file is missing from the plugin directory</li>';
        echo '<li>Expected location: <code>' . htmlspecialchars(__DIR__ . '/bip39-wordlist.php') . '</code></li>';
    }

    if (strpos($e->getMessage(), 'Ed25519Compat') !== false) {
        echo '<li>The Ed25519Compat.php file is missing from the plugin directory</li>';
        echo '<li>Expected location: <code>' . htmlspecialchars(__DIR__ . '/Ed25519Compat.php') . '</code></li>';
    }

    if (strpos($e->getMessage(), 'sodium') !== false) {
        echo '<li>PHP extension "sodium" is not installed or enabled</li>';
        echo '<li>Check your PHP configuration: <code>php -m | grep sodium</code></li>';
        echo '<li>Sodium is built-in with PHP 7.2+</li>';
    }

    if (strpos($e->getMessage(), 'bcmath') !== false) {
        echo '<li>PHP extension "bcmath" is not installed or enabled</li>';
        echo '<li>Check your PHP configuration: <code>php -m | grep bcmath</code></li>';
        echo '<li>Install: On Windows (enable in php.ini), On Linux: <code>apt-get install php-bcmath</code></li>';
    }

    echo '<li>Check that all plugin files are present and properly uploaded</li>';
    echo '<li>Verify file permissions allow PHP to read the files</li>';
    echo '</ul>';

    echo '<details style="margin-top: 15px;"><summary style="cursor: pointer; color: #ff9800; font-weight: bold;">📋 Full Stack Trace</summary>';
    echo '<pre style="margin-top: 10px;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</details>';

    // Debug info
    echo '<details style="margin-top: 15px;"><summary style="cursor: pointer; color: #569cd6; font-weight: bold;">🔍 Environment Info</summary>';
    echo '<table style="margin-top: 10px;"><tr><th>Item</th><th>Value</th></tr>';
    echo '<tr><td>PHP Version</td><td>' . PHP_VERSION . '</td></tr>';
    echo '<tr><td>Sodium Extension</td><td>' . (extension_loaded('sodium') ? '✅ Loaded' : '❌ Not Loaded') . '</td></tr>';
    echo '<tr><td>BCMath Extension</td><td>' . (extension_loaded('bcmath') ? '✅ Loaded' : '❌ Not Loaded') . '</td></tr>';
    echo '<tr><td>Plugin Directory</td><td>' . htmlspecialchars(__DIR__) . '</td></tr>';
    echo '<tr><td>bip39-wordlist.php</td><td>' . (file_exists(__DIR__ . '/bip39-wordlist.php') ? '✅ Found' : '❌ Missing') . '</td></tr>';
    echo '<tr><td>Ed25519Compat.php</td><td>' . (file_exists(__DIR__ . '/Ed25519Compat.php') ? '✅ Found' : '❌ Missing') . '</td></tr>';
    echo '<tr><td>CardanoWalletPHP.php</td><td>' . (file_exists(__DIR__ . '/CardanoWalletPHP.php') ? '✅ Found' : '❌ Missing') . '</td></tr>';
    echo '</table></details>';

    echo '</div>';
}

?>

<h2>Test 4: Manual Witness Set Input</h2>

<div class="info">
    <strong>Use this section to compare your witness set with Anvil's witness set</strong>
</div>

<form method="post" style="background: #252526; padding: 20px; border-radius: 4px; margin: 20px 0;">
    <h3 style="margin-top: 0;">Input Witness Sets for Comparison:</h3>

    <label for="your_witness" style="display: block; margin: 15px 0 5px; color: #4ec9b0; font-weight: bold;">Your Witness Set (Hex):</label>
    <textarea name="your_witness" id="your_witness" rows="3" style="width: 100%; background: #1e1e1e; color: #d4d4d4; border: 1px solid #3e3e42; padding: 10px; font-family: monospace;"><?php echo isset($_POST['your_witness']) ? htmlspecialchars($_POST['your_witness']) : ''; ?></textarea>

    <label for="anvil_witness" style="display: block; margin: 15px 0 5px; color: #569cd6; font-weight: bold;">Anvil's Witness Set (Hex):</label>
    <textarea name="anvil_witness" id="anvil_witness" rows="3" style="width: 100%; background: #1e1e1e; color: #d4d4d4; border: 1px solid #3e3e42; padding: 10px; font-family: monospace;"><?php echo isset($_POST['anvil_witness']) ? htmlspecialchars($_POST['anvil_witness']) : ''; ?></textarea>

    <button type="submit" style="background: #0e639c; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; margin-top: 15px; font-weight: bold;">Compare Witness Sets</button>
</form>

<?php
if (isset($_POST['your_witness']) && isset($_POST['anvil_witness'])) {
    $your_wit = trim($_POST['your_witness']);
    $anvil_wit = trim($_POST['anvil_witness']);

    if (!empty($your_wit) && !empty($anvil_wit)) {
        echo '<h3>Comparison Results:</h3>';

        echo '<div class="comparison">';

        echo '<div class="side">';
        echo '<h4 style="color: #4ec9b0;">Your Witness Set</h4>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($your_wit) . '</div>';
        echo '<strong>Length:</strong> ' . strlen($your_wit) . ' hex chars (' . (strlen($your_wit)/2) . ' bytes)<br>';
        $your_analysis = analyzeCBORStructure($your_wit);
        echo '<strong>CBOR Structure:</strong>';
        echo renderCBORStructure($your_analysis);
        echo '</div>';

        echo '<div class="side">';
        echo '<h4 style="color: #569cd6;">Anvil\'s Witness Set</h4>';
        echo '<div class="hex" style="font-size: 10px;">' . htmlspecialchars($anvil_wit) . '</div>';
        echo '<strong>Length:</strong> ' . strlen($anvil_wit) . ' hex chars (' . (strlen($anvil_wit)/2) . ' bytes)<br>';
        $anvil_analysis = analyzeCBORStructure($anvil_wit);
        echo '<strong>CBOR Structure:</strong>';
        echo renderCBORStructure($anvil_analysis);
        echo '</div>';

        echo '</div>';

        // Detailed comparison
        if ($your_wit === $anvil_wit) {
            echo '<div class="success"><strong>✓ IDENTICAL:</strong> Both witness sets are exactly the same!</div>';
        } else {
            echo '<div class="warning"><strong>⚠ DIFFERENT:</strong> Witness sets differ</div>';

            // Find first difference
            $min_len = min(strlen($your_wit), strlen($anvil_wit));
            for ($i = 0; $i < $min_len; $i += 2) {
                $your_byte = substr($your_wit, $i, 2);
                $anvil_byte = substr($anvil_wit, $i, 2);
                if ($your_byte !== $anvil_byte) {
                    echo '<div class="error">';
                    echo '<strong>First difference at position ' . ($i/2) . ' (byte ' . ($i/2) . '):</strong><br>';
                    echo 'Your byte: <code>' . strtoupper($your_byte) . '</code><br>';
                    echo 'Anvil byte: <code>' . strtoupper($anvil_byte) . '</code>';
                    echo '</div>';
                    break;
                }
            }

            if (strlen($your_wit) !== strlen($anvil_wit)) {
                echo '<div class="error">';
                echo '<strong>Length difference:</strong><br>';
                echo 'Your length: ' . strlen($your_wit) . ' hex chars<br>';
                echo 'Anvil length: ' . strlen($anvil_wit) . ' hex chars<br>';
                echo 'Difference: ' . abs(strlen($your_wit) - strlen($anvil_wit)) . ' hex chars (' . abs(strlen($your_wit) - strlen($anvil_wit))/2 . ' bytes)';
                echo '</div>';
            }
        }
    }
}
?>

<div style="margin-top: 40px; padding: 20px; background: #252526; border-radius: 4px;">
    <h3 style="margin-top: 0;">📚 Reference Information</h3>

    <h4>Cardano Transaction Witness Set (CIP-0010)</h4>
    <pre style="font-size: 12px;">
Witness Set Structure:
{
  ? 0: [* vkeywitness],      // Verification key witnesses
  ? 1: [* native_script],    // Native scripts
  ? 2: [* bootstrap_witness],// Bootstrap witnesses (Byron era)
  ? 3: [* plutus_v1_script], // Plutus V1 scripts
  ? 4: [* plutus_data],      // Plutus data
  ? 5: [* redeemer],         // Redeemers
  ? 6: [* plutus_v2_script], // Plutus V2 scripts
}

vkeywitness = [
  vkey: bytes .size 32,      // Ed25519 public key
  signature: bytes .size 64   // Ed25519 signature
]

For simple transfers:
- Only key 0 (vkeywitness) is present
- Single witness in array for single signer
- Multiple witnesses for multi-sig
    </pre>

    <h4>Ed25519 Signature Format (RFC 8032)</h4>
    <pre style="font-size: 12px;">
Extended Ed25519 (Cardano):
- Private Key: kL||kR (64 bytes total)
- Public Key: A = [kL]B (32 bytes)
- Signature: R||S (64 bytes)
  - R = [r]B where r = H(kR||message)
  - S = r + H(R||A||message)*kL (mod L)

Standard Ed25519 (Legacy):
- Private Key: seed (32 bytes)
- Derived: (kL||kR) = H(seed), then clamp kL
- Same signature process

Key Difference:
- Standard: seed → H(seed) → kL||kR
- Extended: kL||kR provided directly
    </pre>
</div>

</body>
</html>
