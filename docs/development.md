# Develop 3PM locally

3PM runs on WordPress. Use a disposable local instance with PHP 8.3, a WordPress-supported database, and pretty permalinks. Populate it with fictional artists, releases and orders. Use the public site to see how the pieces are assembled.

1. Install WooCommerce, Elementor, Code Snippets, TeraWallet and Paid Memberships Pro as needed. Install your licensed copy of Vice and Vice Dhrk with the Vice Elementor widgets for theme-specific screens and music playback. Install FileBird and FileBird Document Library if testing the historical folder browser.
2. Copy the desired folders from `wordpress/plugins/` into `wp-content/plugins/` and activate the components needed for the feature you are testing. Create WooCommerce account and checkout pages and resave permalinks after enabling account extensions.
3. Build a sample artist with a release and preview track. Add a sample WooCommerce product and assign it to that artist or release through the relevant plugin controls. Enable only the archived snippets relevant to a test in Code Snippets and remove the opening PHP tag when pasting them.
4. Create accounts for a fan, an artist and a store administrator. Exercise public artist and release pages, playback across navigation, playlist creation, account permissions, a sandbox checkout, and an artist service or licence request.
5. For wallet and settlement work, use synthetic transactions and test wallets. The checked-in settlement and Studio config omit production destinations. The independent Solana clients and Rust programs are in `solana/`. Use separate devnet configuration for blockchain tests.

## Checks

From the repository root, run the PHP tests and syntax checks with PHP CLI. Use Node.js for JavaScript syntax checks.

```sh
php tests/payment-lifecycle-test.php
php tests/receipt-verifier-test.php
find wordpress payment-core -name '*.php' -exec php -l {} \;
find wordpress solana \( -name '*.js' -o -name '*.cjs' \) -exec node --check {} \;
```

The payment tests exercise the standalone lifecycle and receipt verifier with fixtures. Browser and WordPress integration checks require a configured instance.

## Source and live-site coverage

| Component | Source in this repository | Version observed on 3PM |
| --- | --- | --- |
| Artist Studio | Packaged source | 0.2.42 |
| Personal Playlists | Packaged source | 1.0.14 |
| Account Insights | Packaged source | 1.1.5 |
| Artist & Service Market | Local source and packaged dependencies | 0.3.8 |
| License Request Tool | Packaged source | 0.3.7 |
| Homepage Rebuild | Local source with a later artist press snapshot | 1.2.0 |
| 3PM Suite | Local source | 1.5.1 |
| Settlement Setup | Packaged source with production destinations removed | 1.0.0 |
| Airdrop Orchestrator | Reviewed local source | 1.0.2 |
| Featured Image Toggle | Reviewed local source | 1.1.1 |
| Media Session Artwork | Reviewed local source | 1.6.0 |
| Aggregate Global Player and FileBird Subfolder Browser | Historical packages | No active entry in the observed inventory |
| Payment lifecycle and receipt verification | Standalone development source | Deployment unverified |
| Theme integrations and snippets | Selected 3PM code and historical snippets | Deployment unverified |

Version matches refer to plugin headers and the active WordPress inventory. Local sources can differ from the files installed on production. WordPress database content, including Elementor layouts configured in the dashboard, production options, user and order data, and private media, remains on the live platform. The commercial theme and vendor plugins are installed separately.
