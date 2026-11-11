<?php
/**
 * Menus: login-state logic for "My Account", "Register", and "Login/Welcome"
 * - Logged OUT:
 *     • "My Account" -> "Register" (links to /membership-levels/), children removed
 *     • "Login"      -> visible + neon #c4cd42
 * - Logged IN:
 *     • "My Account" -> shown normally with children
 *     • "Login/Welcome/Sign In" -> hidden (and children removed)
 * - Applies to all menus (vertical + horizontal)
 */
add_filter('wp_nav_menu_objects', function($items){
    if (empty($items) || !is_array($items)) return $items;

    // normalize helper
    $norm = function($t){
        $t = wp_strip_all_tags((string)$t);
        $t = preg_replace('/[\(\[].*?[\)\]]/u', '', $t);
        $t = preg_replace('/[^a-z0-9 \$%]+/iu', ' ', $t);
        return strtolower(trim(preg_replace('/\s+/u', ' ', $t)));
    };

    // descendant collector
    $collect_descendants = function($parent_id) use (&$items, &$collect_descendants){
        $ids = [];
        foreach ($items as $it) {
            if ((int)$it->menu_item_parent === (int)$parent_id) {
                $ids[] = $it->ID;
                $ids = array_merge($ids, $collect_descendants($it->ID));
            }
        }
        return $ids;
    };

    $logged_in     = is_user_logged_in();
    $register_url  = home_url('/membership-levels/');
    $neon_class    = 'threepm-neon-cta';
    $remove_ids    = [];

    // buckets
    $my_account_ids = [];
    $login_like_ids = []; // will include "Login", "Welcome", "Sign In" etc.

    foreach ($items as $it) {
        $title = $norm($it->title);
        $url   = is_string($it->url ?? null) ? strtolower($it->url) : '';

        // My Account detector (title-based)
        if ($title === 'my account') {
            $my_account_ids[] = $it->ID;
        }

        // Login-like detector (title AND common URL patterns; covers themes renaming Login→Welcome)
        if (in_array($title, ['login','welcome','sign in','sign-in','signin'], true)
            || strpos($url, 'wp-login.php') !== false
            || preg_match('#/(login|sign-in)/?$#', $url)
        ){
            $login_like_ids[] = $it->ID;
        }
    }

    if (!$logged_in) {
        // Turn "My Account" into "Register" + remove its children + add neon
        foreach ($items as $it) {
            if (in_array($it->ID, $my_account_ids, true)) {
                $it->title = 'Register';
                $it->url   = $register_url;

                // remove dropdown class
                if (!empty($it->classes) && is_array($it->classes)) {
                    $it->classes = array_diff($it->classes, ['menu-item-has-children']);
                }

                // remove children of My Account
                $remove_ids = array_merge($remove_ids, $collect_descendants($it->ID));

                // add neon class
                $it->classes[] = $neon_class;
            }
        }

        // Style the Login-like item with same neon (keep visible)
        foreach ($items as $it) {
            if (in_array($it->ID, $login_like_ids, true)) {
                $it->classes[] = $neon_class;
            }
        }

        // Inject neon CSS once
        add_action('wp_footer', function() use ($neon_class){
            static $style_done = false; if ($style_done) return; $style_done = true; ?>
            <style>
                @keyframes threepmNeonPulse {
                    0%, 100% { color: #c4cd42; text-shadow: 0 0 8px #c4cd42, 0 0 20px #c4cd42; }
                    50%      { color: #d6df5a; text-shadow: 0 0 4px #d6df5a, 0 0 10px #d6df5a; }
                }
                .<?php echo esc_attr($neon_class); ?> > a {
                    color: #c4cd42 !important;
                    animation: threepmNeonPulse 1.6s infinite alternate ease-in-out;
                    font-weight: 600;
                }
            </style>
            <?php
        });

    } else {
        // Logged in: remove any Login-like item (including themes renaming it to "Welcome")
        foreach ($login_like_ids as $lid) {
            $remove_ids[] = $lid;
            $remove_ids   = array_merge($remove_ids, $collect_descendants($lid));
        }
        // Leave "My Account" as-is
    }

    if ($remove_ids) {
        $remove_ids = array_unique($remove_ids);
        $items = array_values(array_filter($items, function($it) use ($remove_ids){
            return !in_array($it->ID, $remove_ids, true);
        }));
    }

    return $items;
}, 10, 1);
