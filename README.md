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
- **Live price conversion** — automatically converts your store's fiat prices to crypto using CoinGecko
- **QR codes** — generated for each payment with proper payment URIs (BIP-21, EIP-681, etc.)
- **15-minute price lock** — customers get a fixed crypto amount with a countdown timer
- **Copy-to-clipboard** — one-click copy for both amount and address
- **Order tracking** — crypto payment details stored on each order (network, amount, TX hash)
- **Block explorer links** — TX hashes link to the appropriate block explorer
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
4. You verify the payment on the blockchain (manually or via block explorer).
5. You change the order status to **"Processing"** or **"Completed"**.

## Configuration

### Settings
| Setting | Description |
|---------|-------------|
| **Title** | What customers see at checkout (default: "Pay with Crypto") |
| **Description** | Subtitle shown under the payment method |
| **Payment Window** | Minutes before the quoted price expires (default: 15) |
| **CoinGecko API Key** | Optional API key for higher rate limits |
| **WalletConnect Project ID** | Optional, for future WalletConnect integration |
| **Wallet Addresses** | One field per supported network — only filled ones are enabled |

### Price Conversion
- Prices are fetched from CoinGecko's free API.
- Results are cached for 60 seconds to minimize API calls.
- Supports all fiat currencies that WooCommerce supports (USD, EUR, GBP, etc.).
- Without an API key: ~30 requests/minute. With a free key: much higher.

## Payment Verification

This plugin uses a **manual verification** workflow by default. When a customer pays:

1. The order is set to "On Hold".
2. If the customer provides a TX hash, it's saved to the order and linked to the block explorer.
3. You click the explorer link and confirm the transaction.
4. You update the order status.

### Automating Verification (Advanced)
For automated verification, you could extend this plugin by:
- Using blockchain RPC APIs (Alchemy, Infura, QuickNode) to watch your wallet for incoming transactions.
- Using webhook services that notify you when a payment arrives.
- Building a cron job that checks pending orders against on-chain data.

This is left as an extension point since it requires API keys and varies by chain.

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
