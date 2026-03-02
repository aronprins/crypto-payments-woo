# Crypto Payments for WooCommerce

Accept cryptocurrency payments directly to your own wallets — no third-party payment processor, no fees beyond network gas.

## Supported Cryptocurrencies

### Native Coins
| Coin | Symbol | Network |
|------|--------|---------|
| Bitcoin | BTC | Bitcoin |
| Ethereum | ETH | Ethereum |
| Solana | SOL | Solana |
| BNB | BNB | BNB Chain |
| Polygon | POL | Polygon |
| Avalanche | AVAX | Avalanche C-Chain |
| Arbitrum | ETH | Arbitrum One |
| Optimism | ETH | Optimism |
| Base | ETH | Base |
| Litecoin | LTC | Litecoin |
| Dogecoin | DOGE | Dogecoin |
| XRP | XRP | XRP Ledger |
| TRON | TRX | TRON |

### Stablecoins
| Token | Networks |
|-------|----------|
| USDT | Ethereum, BNB Chain, Polygon, TRON |
| USDC | Ethereum, BNB Chain, Polygon, Arbitrum, Base, Solana |

## Features

- **Direct-to-wallet payments** — funds go straight to your addresses, no intermediary
- **Automatic payment verification** — checks the blockchain every 3 minutes and auto-completes orders when payment is confirmed
- **Live price conversion** — automatically converts your store's fiat prices to crypto using CoinGecko
- **QR codes** — generated for each payment with proper payment URIs (BIP-21, EIP-681, etc.)
- **Configurable price lock** — customers get a fixed crypto amount with a countdown timer (default: 15 minutes)
- **Unique payment amounts** — optional dust amounts prevent payment collisions when multiple customers pay simultaneously
- **Copy-to-clipboard** — one-click copy for both amount and address
- **Order tracking** — crypto payment details stored on each order (network, amount, TX hash)
- **Block explorer links** — TX hashes link to the appropriate block explorer
- **WooCommerce Blocks support** — works with both classic and block-based checkout
- **WooCommerce HPOS compatible** — works with High-Performance Order Storage
- **Mobile-responsive** — clean checkout UI on all devices

## Installation

1. Download or clone this plugin into your `/wp-content/plugins/` directory:
   ```
   wp-content/plugins/crypto-payments-woo/
   ```

2. Activate the plugin in **WordPress → Plugins**.

3. Go to **WooCommerce → Settings → Payments → Crypto Payments**.

4. Click **Enable Crypto Payments**.

5. Enter your wallet addresses for each cryptocurrency you want to accept. Only cryptocurrencies with a configured address will appear at checkout.

6. (Optional) Add a **CoinGecko API Key** for higher rate limits on price lookups. Get a free one at [coingecko.com/en/api](https://www.coingecko.com/en/api).

7. (Optional) Add a **WalletConnect Project ID** if you plan to add WalletConnect support later. Get one free at [cloud.walletconnect.com](https://cloud.walletconnect.com).

## How It Works

### Customer Flow
1. Customer adds items to cart and proceeds to checkout.
2. Customer selects **"Pay with Crypto"** as their payment method.
3. Customer chooses their preferred cryptocurrency from the dropdown.
4. The plugin fetches the live exchange rate and displays:
   - The exact crypto amount to send
   - Your wallet address with a QR code
   - A 15-minute countdown timer
5. Customer sends the crypto from their wallet.
6. Customer (optionally) pastes their transaction hash.
7. Customer places the order.

### Store Owner Flow
1. Orders paid with crypto are set to **"On Hold"** status.
2. You receive the standard WooCommerce new order email.
3. The order includes crypto payment details: network, amount, TX hash, and block explorer link.
4. If **Auto-Verification** is enabled, the plugin checks the blockchain every 3 minutes and automatically moves the order to **"Processing"**/**"Completed"** when enough confirmations are reached.
5. If auto-verification is disabled, you verify the payment manually via the block explorer link and update the order status yourself.

## Configuration

### Settings
| Setting | Description |
|---------|-------------|
| **Title** | What customers see at checkout (default: "Pay with Crypto") |
| **Description** | Subtitle shown under the payment method |
| **Payment Window** | Minutes before the quoted price expires (default: 15) |
| **CoinGecko API Key** | Optional API key for higher rate limits |
| **WalletConnect Project ID** | Optional, for WalletConnect integration on EVM chains |
| **Wallet Addresses** | One field per supported network — only filled ones are enabled |
| **Enable Auto-Verification** | Automatically verify payments on-chain and complete orders |
| **Unique Payment Amounts** | Add tiny dust amounts for reliable payment matching (default: on) |
| **EVM Explorer API Keys** | Free API keys for Etherscan, BscScan, PolygonScan, etc. (required for EVM auto-verification) |

### Price Conversion
- Prices are fetched from CoinGecko's free API.
- Results are cached for 60 seconds to minimize API calls.
- Supports all fiat currencies that WooCommerce supports (USD, EUR, GBP, etc.).
- Without an API key: ~30 requests/minute. With a free key: much higher.

## Payment Verification

### Automatic Verification (Recommended)

Enable **Auto-Verification** in the plugin settings to have the plugin automatically check the blockchain and complete orders. The plugin polls on-chain data every 3 minutes via WP-Cron.

**Supported chains and APIs:**

| Chain | API Used | API Key Required? |
|-------|----------|-------------------|
| Ethereum, Polygon, Arbitrum, Optimism, Base, BNB Chain, Avalanche | Etherscan-family APIs | Yes (free) |
| Bitcoin | mempool.space | No |
| Litecoin, Dogecoin | Blockchair | No |
| Solana | Solana RPC / Solscan | No |
| XRP | XRPL JSON-RPC | No |
| TRON | Tronscan / TronGrid | No |

**How it works:**
1. The order is set to "On Hold" at checkout.
2. Every 3 minutes, the plugin checks all on-hold crypto orders (up to 50, within the last 48 hours).
3. For each order, it queries the appropriate blockchain API using the TX hash (if provided) or by scanning recent transactions to your wallet.
4. When enough confirmations are reached, the order is automatically moved to "Processing"/"Completed".
5. All verification activity is logged to **WooCommerce > Status > Logs > crypto-payments**.

**WP-Cron note:** WP-Cron only fires on page visits. For reliable 3-minute intervals on low-traffic sites, set up a real system cron:
```
*/3 * * * * wget -q -O /dev/null https://yoursite.com/wp-cron.php?doing_wp_cron
```

### Manual Verification

If auto-verification is disabled, you can verify payments manually:

1. The order is set to "On Hold".
2. If the customer provides a TX hash, it's saved to the order and linked to the block explorer.
3. Click the explorer link on the order to confirm the transaction.
4. Update the order status manually.

## Adding More Cryptocurrencies

To add a new network, edit `includes/class-cpw-networks.php` and add an entry to the `get_all()` method:

```php
'newcoin' => [
    'name'           => 'New Coin',
    'symbol'         => 'NEW',
    'coingecko_id'   => 'newcoin',        // Must match CoinGecko's API ID
    'decimals'       => 18,
    'is_evm'         => false,
    'is_token'       => false,
    'chain_id'       => null,
    'token_contract' => '',
    'explorer_tx'    => 'https://explorer.newcoin.io/tx/{tx}',
    'icon'           => '⬡',
    'color'          => '#FF6600',
],
```

The new coin will automatically appear in settings (for wallet address) and at checkout.

## Security Notes

- **No private keys** are ever stored or used by this plugin. You only enter public receiving addresses.
- All AJAX requests are protected with WordPress nonces.
- Wallet addresses are stored in WooCommerce's encrypted settings.
- Customer-submitted TX hashes are sanitized before storage.
- The plugin never sends funds — it only displays your address for receiving.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+

## License

GPL-2.0+
