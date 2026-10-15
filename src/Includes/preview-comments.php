<?php
/**
 * Replace native comments with the reviewer form on valid shared previews.
 *
 * @package PreviewShare
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'previewshare_review_comments_slot' );
