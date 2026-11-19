<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package Webtrix_Product_Suggestions
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wtx_ps_options' );
delete_post_meta_by_key( '_wtx_ps_ids' );
