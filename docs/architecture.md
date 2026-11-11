# Platform architecture

This map describes the public product and the WordPress components identified in the available 3PM source. The archived packages are historical source snapshots. Their versions may differ from the live deployment.

| Layer | Components | Responsibilities |
| --- | --- | --- |
| Content and presentation | WordPress, Vice theme, Elementor | Render releases, artist pages, editorial content and templates. |
| Playback | Vice player and 3PM extensions | Build a cross-release playlist, retain links back to each release and populate browser media metadata. |
| Commerce and accounts | WooCommerce and 3PM integrations | Handle products, checkout, orders and customer account views. |
| Artist profiles | WordPress artist posts and custom profile code | Store profile details and present them through Elementor shortcode widgets. Recent work includes press links and account-dashboard controls. |
| Membership and support | Vaults, membership experience and platform rewards | Give fans ways to access member content and support artists. See the [public fan page](https://3rdpersonmusic.com/for-fans/) for eligibility and terms. |

## How the components fit together

- **Release to player.** WordPress stores releases and their track lists. The archived aggregate-player extension reads the published release tracks, builds a playlist and passes it to the Vice player with the release links intact.
- **Player to device.** The archived Media Session extension reads the player state and provides artwork and track details to browser and device media controls.
- **Artist to profile.** Profile fields live with the artist post. Elementor renders the public profile through a custom shortcode. WooCommerce account hooks support artist-facing updates.
- **Editor to post.** The featured image toggle saves a preference on a post or page and uses WordPress rendering filters to hide the image on its individual page.

## Source inventory

| Component | Repository status |
| --- | --- |
| 3PM Featured Image Toggle 1.1.0 | [Published source](../wordpress/plugins/threepm-featured-image-toggle/threepm-featured-image-toggle.php) |
| Vice aggregate player and Media Session artwork | Archived source packages located. Verify the release version and dependencies before publishing a deployment package. |
| FileBird subfolder browser and airdrop integration | Archived source packages located. Review data access, configuration and deployment status before public release. |
| Artist profile and press tools | Development snapshots located. Reconcile with the deployed revision before publishing. |

## Working with the published plugin

Copy the `threepm-featured-image-toggle` folder into `wp-content/plugins/` in a local or staging WordPress installation and activate it. In the post or page editor, use **Featured Image Display** to toggle the behavior. Check an enabled and disabled post, a page that uses the Featured Image block, and an archive view. The plugin header identifies its license as GPL-2.0+.
