# PHP Cardano

**Pure PHP implementation of Cardano wallet generation and transaction signing with zero external dependencies.**

Generate wallets, derive keys, and sign transactions using only PHP native extensions - no Python, no Node.js, no external binaries required.

---

## ⚠️ Beta Status

**This library is currently in BETA.** While the core cryptographic implementations have been extensively tested and follow Cardano standards (CIP-1852, Ed25519-BIP32, Icarus derivation), the following would be valuable:

- **Community testing** across different PHP environments and versions
- **Feedback** on API design and developer experience
- **Bug reports** and edge case discoveries
- **Code review** from cryptography and Cardano experts
- **Performance benchmarks** on various hosting configurations

**Use with caution in production.** Always test with small amounts on preprod/testnet first.

👉 **Found an issue or want to contribute?** Open an issue on GitHub for any feedback!

---

## Key Features

- ✅ **CIP-1852 compliant** HD wallet derivation (m/1852'/1815'/0'/0/0)
- ✅ **Icarus root key generation** using PBKDF2-HMAC-SHA512 with correct clamping
- ✅ **Ed25519-BIP32** (Khovratovich/Law) child key derivation
- ✅ **Extended key signing** (kL||kR) for proper Cardano transaction signatures
- ✅ **Pure PHP CBOR codec** for encoding/decoding transaction structures
- ✅ **No external dependencies** - uses only PHP built-in extensions
- ✅ **Multi-layer fallback system**: native sodium → FFI → pure PHP (BCMath)
- ✅ **BIP39 mnemonic** generation and restoration (12-24 words)
- ✅ **Bech32 address encoding** for mainnet and testnet addresses
- ✅ **WordPress plugin integration** with admin interface and Anvil API support

---

## Technical Requirements

### Minimum Requirements
- **PHP 7.2+** (PHP 8.0+ recommended for best performance)
- **ext/sodium** (built-in since PHP 7.2)
- **ext/hash** (built-in)
- **ext/bcmath** (for pure-PHP fallback arithmetic)

### Optional (for improved performance)
- **ext/ffi** (PHP 7.4+) - enables FFI fallback for faster crypto operations
- **libsodium library** accessible to FFI - provides optimized Ed25519 operations

### Performance Hierarchy
The library automatically selects the best available cryptographic backend:

1. **Native Sodium** (PHP 8.3+) - Uses built-in `sodium_crypto_scalarmult_ed25519_base_noclamp()` ⚡ FASTEST
2. **FFI to libsodium** (PHP 7.4+, FFI enabled) - Direct calls to C library 🚀 FAST
3. **Pure PHP BCMath** (Fallback) - Works everywhere, slower for complex operations 🐢 COMPATIBLE

---

## Architecture Overview

### Core Components

| Component | Purpose |
|-----------|---------|
| `CardanoWalletPHP.php` | Wallet generation, BIP39 mnemonic handling, CIP-1852 key derivation, address generation |
| `CardanoTransactionSignerPHP.php` | Transaction signing, CBOR encoding/decoding, witness set construction |
| `Ed25519Compat.php` | Ed25519 compatibility layer with triple-fallback (native/FFI/pure) |
| `Ed25519Pure.php` | Pure PHP Ed25519 implementation using BCMath (fallback) |
| `bip39-wordlist.php` | BIP39 English wordlist (2048 words) |
| `cardano-wallet-test.php` | WordPress plugin integration with admin UI |

---

## How It Works

### 1. Wallet Generation & Derivation

```
Mnemonic (24 words)
    ↓
Entropy (256 bits via BIP39)
    ↓
Root Key (Icarus): PBKDF2-HMAC-SHA512(passphrase, salt=entropy, iter=4096, dkLen=96)
    ├─ kL (32 bytes) → Icarus clamp: kL[0]&=0xF8, kL[31]&=0x1F, kL[31]|=0x40
    ├─ kR (32 bytes)
    └─ chainCode (32 bytes)
    ↓
CIP-1852 Derivation: m/1852'/1815'/0'/0/0
    ↓
Ed25519-BIP32 Child Derivation (Khovratovich/Law)
    - Hardened: uses kL||kR (64 bytes)
    - Non-hardened: uses public key A
    - Z-split: left 28 bytes → pad 4 zeros, skip Z[28..31], right 32 bytes
    - kL_child = kL_parent + 8*ZL (mod 2^256, raw addition for CSL compatibility)
    - kR_child = kR_parent + ZR (mod 2^256)
    ↓
Payment Key (m/.../0/0) + Stake Key (m/.../2/0)
    ↓
Addresses (Base: payment keyhash + stake keyhash, Bech32 encoded)
```

### 2. Transaction Signing Flow

```
CBOR Transaction (hex)
    ↓
Extract Original Body Bytes (CRITICAL: preserve exact CBOR structure)
    ↓
Blake2b-256 Hash of Body → Transaction Hash
    ↓
Extended Key Signing (NO-CLAMP Ed25519)
    - Derive public key: A = kL * G (no clamp)
    - r = reduce(SHA512(kR || txHash))
    - R = r * G
    - h = reduce(SHA512(R || A || txHash))
    - S = r + h*kL (mod L)
    - Signature = R || S (64 bytes)
    ↓
Construct Witness Set (CBOR map with tag 258 for set)
    ↓
Signed Transaction (ready for submission)
```

---

## Installation

### As a WordPress Plugin

1. Clone or download this repository
2. Place in your WordPress plugins directory: `wp-content/plugins/php-cardano/`
3. Activate via WordPress admin panel
4. Navigate to "Wallet Test" in WordPress admin menu

### As a Standalone PHP Library

```php
require_once 'CardanoWalletPHP.php';
require_once 'CardanoTransactionSignerPHP.php';
```

---

## Usage Examples

### Generate a New Wallet

```php
require_once 'CardanoWalletPHP.php';

// Generate a new 24-word wallet for preprod testnet
$wallet = CardanoWalletPHP::generateWallet('preprod');

if ($wallet['success']) {
    echo "Mnemonic: " . $wallet['mnemonic'] . "\n";
    echo "Payment Address: " . $wallet['addresses']['payment_address'] . "\n";
    echo "Stake Address: " . $wallet['addresses']['stake_address'] . "\n";
    echo "Payment Private Key (extended): " . $wallet['payment_skey_extended'] . "\n";
}
```

### Restore Wallet from Mnemonic

```php
require_once 'CardanoWalletPHP.php';

$mnemonic = "your twenty four word mnemonic phrase here goes like this and more words continue";
$passphrase = ""; // Optional BIP39 passphrase
$network = "mainnet"; // or "preprod"

$wallet = CardanoWalletPHP::fromMnemonic($mnemonic, $passphrase, $network);

if ($wallet['success']) {
    echo "Payment Address: " . $wallet['addresses']['payment_address'] . "\n";
    echo "Payment Key Hash: " . $wallet['payment_keyhash'] . "\n";
    echo "Stake Key Hash: " . $wallet['stake_keyhash'] . "\n";

    // Extended keys (kL||kR) needed for transaction signing
    $payment_extended_key = $wallet['payment_skey_extended']; // 128 hex chars
    $stake_extended_key = $wallet['stake_skey_extended']; // 128 hex chars
}
```

### Sign a Transaction

```php
require_once 'CardanoTransactionSignerPHP.php';

// Transaction body as CBOR hex (from your transaction builder)
$tx_cbor_hex = "84a400818258203d..."; // Your unsigned transaction

// Extended private key (128 hex chars = 64 bytes = kL||kR)
$payment_skey_extended = "a0b1c2d3..."; // From wallet generation/restoration

// Sign the transaction
$result = CardanoTransactionSignerPHP::signTransaction(
    $tx_cbor_hex,
    $payment_skey_extended,
    true // Enable debug logging
);

if ($result['success']) {
    echo "Signed Transaction: " . $result['signedTx'] . "\n";
    echo "Witness Set: " . $result['witnessSetHex'] . "\n";
    echo "Public Key: " . $result['vkey_hex'] . "\n";
    echo "Signature: " . $result['sig_hex'] . "\n";

    // Debug information
    foreach ($result['debug'] as $log) {
        echo $log . "\n";
    }
} else {
    echo "Error: " . $result['error'] . "\n";
}
```

### WordPress Plugin Usage

1. Configure your Anvil API keys in the admin interface
2. Use the "Configuration" tab to test wallet generation and restoration
3. Use the "Build & Sign" tab to construct and sign transactions
4. Use the "Submit Transaction" tab to broadcast to the network

---

## API Reference

### CardanoWalletPHP

#### `CardanoWalletPHP::generateWallet(string $network = 'preprod'): array`

Generates a new random wallet with a 24-word mnemonic.

**Parameters:**
- `$network` - Network ID: `'mainnet'` or `'preprod'` (default: `'preprod'`)

**Returns:**
```php
[
    'success' => true,
    'mnemonic' => string,                    // 24-word BIP39 mnemonic
    'root' => [...],                         // Root key data
    'account' => [...],                      // Account key data (m/1852'/1815'/0')
    'payment' => [...],                      // Payment key data (m/.../0/0)
    'stake' => [...],                        // Stake key data (m/.../2/0)
    'addresses' => [
        'payment_address' => string,         // Bech32 base address
        'stake_address' => string            // Bech32 stake address
    ],
    'payment_skey_hex' => string,            // kL only (64 hex chars)
    'payment_skey_extended' => string,       // kL||kR (128 hex chars) - USE THIS FOR SIGNING
    'payment_pkey_hex' => string,            // Public key (64 hex chars)
    'payment_keyhash' => string,             // Blake2b-224 hash of public key (56 hex chars)
    'stake_skey_hex' => string,              // Stake kL (64 hex chars)
    'stake_skey_extended' => string,         // Stake kL||kR (128 hex chars)
    'stake_keyhash' => string                // Stake key hash (56 hex chars)
]
```

#### `CardanoWalletPHP::fromMnemonic(string $mnemonic, string $passphrase = '', string $network = 'preprod'): array`

Restores a wallet from a BIP39 mnemonic phrase.

**Parameters:**
- `$mnemonic` - BIP39 mnemonic (12, 15, 18, 21, or 24 words)
- `$passphrase` - Optional BIP39 passphrase (default: `''`)
- `$network` - Network ID: `'mainnet'` or `'preprod'` (default: `'preprod'`)

**Returns:** Same structure as `generateWallet()` (without `mnemonic` field)

### CardanoTransactionSignerPHP

#### `CardanoTransactionSignerPHP::signTransaction(string $tx_hex, string $skey_hex, bool $debug = false): array`

Signs a CBOR-encoded transaction with an extended private key.

**Parameters:**
- `$tx_hex` - CBOR-encoded unsigned transaction (hex string)
- `$skey_hex` - Extended private key (`kL||kR`, 128 hex chars) or legacy seed (64 hex chars)
- `$debug` - Enable detailed debug logging (default: `false`)

**Returns:**
```php
[
    'success' => true,
    'signedTx' => string,           // Complete signed transaction (CBOR hex)
    'witnessSetHex' => string,      // Witness set for API submission (CBOR hex)
    'vkey_hex' => string,           // Public verification key (64 hex chars)
    'sig_hex' => string,            // Ed25519 signature (128 hex chars)
    'debug' => array                // Debug log messages (if debug=true)
]
```

---

## Security Considerations

### Extended Key Handling

This library uses **extended Ed25519 keys** (kL||kR, 64 bytes total) for signing, which is critical for Cardano compatibility:

- ✅ **USE:** `payment_skey_extended` (128 hex chars) for transaction signing
- ❌ **DON'T USE:** `payment_skey_hex` (64 hex chars) - this is kL only, insufficient for signing

### No-Clamp Ed25519

Cardano uses **no-clamp Ed25519** signatures, which differs from standard RFC 8032:

- Standard Ed25519 clamps the scalar during signing
- Cardano does NOT clamp during signing (but does clamp during key generation)
- This library correctly implements both behaviors via `Ed25519Compat::sign_extended()`

### CBOR Structure Preservation

When signing transactions, the **original CBOR bytes** must be preserved:

- The library extracts body bytes WITHOUT decoding/re-encoding
- Re-encoding changes CBOR structure and produces different transaction hashes
- This is handled automatically by `CardanoTransactionSignerPHP::extractBodyBytes()`

### Memory Zeroing

Sensitive values are securely erased after use (when `sodium_memzero` is available):

- Derived key material after extraction
- Intermediate signature values (r, h, hkL)

### Private Key Storage

- **NEVER** commit mnemonics or private keys to version control
- Use environment variables or secure key management systems
- For WordPress: keys are stored in WordPress options (encrypted storage recommended)

---

## Performance Notes

### Fallback Hierarchy

The library automatically detects and uses the fastest available backend:

| Backend | Wallet Generation | Transaction Signing | Notes |
|---------|------------------|---------------------|-------|
| **Native Sodium** (PHP 8.3+) | ~50ms | ~5ms | Best performance, requires latest PHP |
| **FFI libsodium** (PHP 7.4+) | ~100ms | ~10ms | Good performance, requires FFI enabled |
| **Pure PHP BCMath** | ~2000ms | ~50ms | Slower, but works everywhere |

*Benchmarks approximate, tested on modern hardware. Your mileage may vary.*

### Improving Performance

1. **Upgrade to PHP 8.3+** for native no-clamp Ed25519 functions
2. **Enable FFI** in php.ini: `ffi.enable = "true"` (or `"preload"` for production)
3. **Ensure libsodium** is accessible to FFI (usually installed by default on Linux)
4. **Use BCMath only as fallback** - consider warning users if pure PHP is used

### Checking Your Backend

```php
require_once 'Ed25519Compat.php';

Ed25519Compat::init();

if (Ed25519Compat::hasNative()) {
    echo "Using native sodium (fastest)\n";
} elseif (Ed25519Compat::hasFFI()) {
    echo "Using FFI to libsodium (fast)\n";
} else {
    echo "Using pure PHP BCMath (slower)\n";
}
```

---

## WordPress Integration

### Admin Interface

The plugin provides a WordPress admin interface with three main tabs:

1. **Configuration**
   - Save Anvil API keys (mainnet/preprod)
   - Test API connectivity
   - Generate new wallets
   - Restore wallets from mnemonic

2. **Build & Sign Transactions**
   - Construct simple payment transactions
   - Sign with stored or provided keys
   - View transaction details and witness sets

3. **Submit Transaction**
   - Submit signed transactions to Anvil API
   - View submission responses
   - Track transaction status

### Anvil API Integration

The plugin integrates with [Ada Anvil](https://ada-anvil.io/) for blockchain interaction:

- Transaction building via `/services/txs/build` endpoint
- Transaction submission via `/services/txs/submit` endpoint
- Supports both mainnet and preprod networks
- Requires API key (get one at ada-anvil.io)

---

## Compatibility & Standards

This library implements the following Cardano and cryptographic standards:

- **[CIP-1852](https://cips.cardano.org/cips/cip1852/)** - HD Wallets for Cardano
- **[BIP39](https://github.com/bitcoin/bips/blob/master/bip-0039.mediawiki)** - Mnemonic code for generating deterministic keys
- **Ed25519-BIP32** (Khovratovich/Law) - Hierarchical deterministic key derivation for Ed25519
- **Icarus Derivation** - PBKDF2-HMAC-SHA512 with specific clamping rules
- **[RFC 8949](https://datatracker.ietf.org/doc/html/rfc8949)** - CBOR encoding/decoding
- **[RFC 8032](https://datatracker.ietf.org/doc/html/rfc8032)** - Ed25519 signatures (with Cardano no-clamp modifications)
- **Bech32** - Address encoding (Bitcoin BIP 173)
- **Blake2b** - Cryptographic hash function (key hashes use Blake2b-224)

---

## Troubleshooting

### "Signature verification failed" on Cardano node

**Cause:** Using `payment_skey_hex` (64 chars) instead of `payment_skey_extended` (128 chars)

**Solution:** Always use the extended key for signing:
```php
$skey = $wallet['payment_skey_extended']; // 128 hex chars (kL||kR)
```

### "Transaction hash mismatch"

**Cause:** CBOR structure was modified during encoding/decoding

**Solution:** This should not happen with the library - the `extractBodyBytes()` method preserves original CBOR. If you see this error, please report it as a bug.

### Slow wallet generation/signing

**Cause:** Using pure PHP BCMath fallback

**Solution:**
1. Check which backend is active (see Performance Notes)
2. Upgrade PHP version or enable FFI
3. Ensure libsodium is accessible

### "ext/sodium is required"

**Cause:** PHP installation missing sodium extension

**Solution:** Sodium is built-in since PHP 7.2, but may be disabled. On Ubuntu/Debian:
```bash
sudo apt-get install php-sodium
sudo systemctl restart php-fpm
```

### "BCMath required for pure-PHP scalar arithmetic"

**Cause:** BCMath extension not installed

**Solution:** Install BCMath:
```bash
# Ubuntu/Debian
sudo apt-get install php-bcmath

# Enable in php.ini
extension=bcmath
```

### WordPress plugin not appearing

**Cause:** File permissions or WordPress not detecting plugin

**Solution:**
1. Ensure plugin directory is in `wp-content/plugins/`
2. Check file permissions: `chmod 755` on directories, `chmod 644` on PHP files
3. Verify plugin header in `cardano-wallet-test.php` (lines 2-7)

---

## Testing & Development

### Running Tests

Test files are located in:
- `test-master.php` - Main test suite
- `test-witness-diagnostics.php` - Transaction signing diagnostics
- `archive/tests/` - Additional test vectors and utilities

### Debug Mode

Enable debug logging in transaction signing:
```php
$result = CardanoTransactionSignerPHP::signTransaction($tx_hex, $skey_hex, true);

// View debug output
print_r($result['debug']);
```

### Project Structure

```
php-cardano/
├── CardanoWalletPHP.php              # Core wallet & key derivation
├── CardanoTransactionSignerPHP.php   # Transaction signing & CBOR
├── Ed25519Compat.php                 # Ed25519 compatibility layer
├── Ed25519Pure.php                   # Pure PHP Ed25519 implementation
├── bip39-wordlist.php                # BIP39 English wordlist
├── cardano-wallet-test.php           # WordPress plugin main file
├── assets/                           # Plugin assets (CSS, JS, images)
├── archive/                          # Additional documentation & tests
│   ├── documentation/                # Technical docs and explanations
│   ├── tests/                        # Test vectors and validation
│   ├── standalone-tests/             # Isolated test scripts
│   └── debug-utilities/              # Debugging helpers
├── test-master.php                   # Main test suite
├── test-witness-diagnostics.php      # Signing diagnostics
├── LICENSE                           # License file
└── README.md                         # This file
```

---

## Contributing

Contributions are welcome! This project is in **active development** and the following are needed:

### High Priority
- 🧪 **Test coverage** - especially across different PHP versions and environments
- 🐛 **Bug reports** - found an edge case? Please report it!
- 📊 **Performance benchmarks** - help optimize for different setups
- 🔐 **Security reviews** - cryptographic code review from experts

### Also Welcome
- 📝 Documentation improvements
- ✨ Feature requests (within scope of pure PHP)
- 🎨 WordPress UI/UX enhancements
- 🌍 Internationalization

### How to Contribute

1. **Open an issue** describing the problem or feature
2. **Fork the repository** and create a branch
3. **Make your changes** with clear commit messages
4. **Test thoroughly** - include test cases if possible
5. **Submit a pull request** with detailed description

### Testing Checklist

When testing, please verify:
- [ ] Wallet generation produces valid Cardano addresses
- [ ] Mnemonic restoration matches expected keys/addresses
- [ ] Signed transactions are accepted by Cardano node
- [ ] Test with both mainnet and preprod networks
- [ ] Performance is acceptable for your use case
- [ ] Error handling works as expected

---

## Known Issues & Roadmap

### Current Limitations
- Multi-signature transactions not yet supported
- Script (Plutus) addresses not implemented
- Metadata attachment in transactions not supported
- Native asset (token) handling not implemented

### Planned Features
- [ ] CIP-30 wallet connector compatibility
- [ ] Multi-signature transaction support
- [ ] Transaction metadata attachment
- [ ] Native asset/token support
- [ ] Byron address support
- [ ] Ledger/Trezor hardware wallet integration via FFI

---

## FAQ

**Q: Is this production-ready?**
A: This is currently in **BETA**. Core functionality is tested and follows Cardano standards, but thorough testing in your environment is recommended before production use. Always test with small amounts on testnet first.

**Q: Why pure PHP? Aren't there better languages for crypto?**
A: Pure PHP enables Cardano functionality in environments where installing external dependencies (Python, Node.js, Rust binaries) is difficult or impossible - shared hosting, WordPress.com, managed platforms, etc.

**Q: What about performance?**
A: Performance is acceptable for most use cases. With native sodium (PHP 8.3+) or FFI, operations are fast. Pure BCMath fallback is slower but still usable for wallet generation and occasional signing.

**Q: Can I use this without WordPress?**
A: Yes! The core libraries (`CardanoWalletPHP.php`, `CardanoTransactionSignerPHP.php`, `Ed25519Compat.php`) work standalone. WordPress integration is optional.

**Q: How do I verify signatures are correct?**
A: Sign a test transaction and submit to preprod network. If accepted by the node, signatures are valid. The library includes diagnostic tools in `test-witness-diagnostics.php`.

**Q: Is my mnemonic/private key secure?**
A: The library uses best practices (memory zeroing, secure key derivation), but security also depends on your environment. Never log or display private keys. Use secure storage for production.

**Q: Can I generate addresses without WordPress?**
A: Yes, use `CardanoWalletPHP::generateWallet()` or `fromMnemonic()` in any PHP script.

---

## Support & Community

- **GitHub Issues:** [Report bugs or request features](https://github.com/invalidcredentials/PHP-Cardano/issues)
- **Discussions:** Share your use cases and get help
- **Email:** pb@ada-anvil.io

---

## License

This project is open source and available under the terms specified in the [LICENSE](LICENSE) file.

---

## Acknowledgments

Built with reference to:
- [Cardano Ledger Specifications](https://github.com/IntersectMBO/cardano-ledger)
- [CIP Standards](https://cips.cardano.org/)
- [cardano-serialization-lib](https://github.com/Emurgo/cardano-serialization-lib)
- [PyCardano](https://github.com/Python-Cardano/pycardano)
- BIP39 and Ed25519-BIP32 specifications

Special thanks to the Cardano developer community for documentation and test vectors.

---

**Remember: This is BETA software. Test thoroughly, report issues, and contribute back!**

*Generated with assistance from Claude Code*
