# Platform architecture

3PM is a WordPress platform with custom PHP plugins, browser JavaScript and CSS, and WooCommerce for commerce. The [development guide](development.md) lists the dependencies and compares available source with the observed live plugin versions.

| Flow | Components | Responsibilities |
| --- | --- | --- |
| Discover and listen | Homepage Rebuild, Vice, Media Session Artwork, Personal Playlists | Present releases and artists, play tracks, populate device controls, and save personal playlists. |
| Publish and manage | Artist Studio, artist profile tools, Account Insights | Edit release and artist details, maintain public profiles and press links, and present account activity. |
| Buy and support | WooCommerce, 3PM Suite, TeraWallet, membership integration | Handle store orders, wallets, top-ups and customer account views. |
| Buy a creative service | Artist & Service Market | Manage artist approval, offerings, requests and agreements. |
| Request a licence | License Request Tool | Collect music licence requests and manage their progression. |
| Fulfil a digital reward | Airdrop Orchestrator | Track eligible order items and record fulfilment. |
| Develop settlement | Settlement Setup, payment core and Solana code | Define destinations, model agreements and verify transaction receipts in development and tests. |

## Data boundaries

WordPress posts and metadata hold profiles, releases and track lists. The music player uses release data, while account and order state comes from WordPress and WooCommerce. Custom plugins extend those interfaces and provide account endpoints and admin screens. Elementor template composition and site settings also live in WordPress. Keep local development self-contained by using fixture content and sandbox payments.
