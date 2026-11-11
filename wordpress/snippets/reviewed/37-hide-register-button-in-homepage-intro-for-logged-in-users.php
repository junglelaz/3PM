<?php
/**
 * Hide homepage Register button for logged-in users
 */
add_action('wp_footer', function(){
    if ( is_user_logged_in() ) : ?>
        <style>
            /* Hide only the Register button when user is logged in */
            .e-lazyloaded .elementor-widget-vice-elementor-button:nth-child(2) .vice-btn {
                display: none !important;
            }
        </style>
    <?php endif;
});
