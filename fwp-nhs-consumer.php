<?php
/*
Plugin Name: FWP+: NHS Feeds Consumer
Plugin URI: https://github.com/radgeek/fwp-nhs-consumer
Description: A FeedWordPress filter that fetches full content and locally caches images from UK NHS feeds you syndicate, and adds additional options to help with workflow in processing content from NHS feeds.
Author: Charles Johnson
Version: 2014.0627
Author URI: http://feedwordpress.radgeek.com/
*/

define('FWPNFC_CACHE_IMAGES_DEFAULT', 'no');
define('FWPNFC_GRAB_FULL_HTML_DEFAULT', 'no');
define('FWPNFC_USE_TITLE_FOR_REUTERS_GUID_DEFAULT', 'yes');
define('FWPNFC_PROCESS_POSTS_MAX', 70);

global $fwpnfc_path;

// Get the path relative to the plugins directory in which FWP is stored
preg_match (
	'|'.preg_quote(WP_PLUGIN_DIR).'/(.+)$|',
	dirname(__FILE__),
	$ref
);

if (isset($ref[1])) :
	$fwpnfc_path = $ref[1];
else : // Something went wrong. Let's just guess.
	$fwpnfc_path = 'fwp-nhs-consumer';
endif;

class FWPNHSFeedsConsumer {
	private $name;
	function __construct () {
		$this->name = strtolower(get_class($this));
		add_action('init', array($this, 'init'));
		add_action('feedwordpress_post_edit_controls', array($this, 'feedwordpress_post_edit_controls'), 10, 1);
		add_action('feedwordpress_save_edit_controls', array($this, 'feedwordpress_save_edit_controls'), 10, 1);
	} /* FWPNHSFeedsConsumer::__construct () */
	
	function init () {
		$taxonomies = get_object_taxonomies('post', 'names');
		register_post_type('syndicatedreview', array(
			'labels' => array(
				'name' => 'Review Items',
				'singular_name' => 'Review Item',
				'menu_name' => 'Review Queue',
			),
			'exclude_from_search' => true,
			'public' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'show_in_admin_bar' => true,
			'menu_position' => 6,
			'hierarchical' => false,
			'supports' => array(
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'trackbacks',
				'custom-fields',
				'comments',
				'revisions',
				'page-attributes',
				'post-formats',
			),
			'taxonomies' => $taxonomies,
		));
	} /* FWPNHSFeedsConsumer::init () */
	
	function feedwordpress_post_edit_controls ($post) {
		if ($post->post_type == 'syndicatedreview' or $post->post_type=='post') :
		?><p><label><strong>In:</strong>
<select name="fwpnhsfeedsconsumer_post_type">
<option value="syndicatedreview"<?php if ($post->post_type == 'syndicatedreview') : ?> selected="selected"<?php endif; ?>>Review Queue</option>
<option value="post"<?php if ($post->post_type == 'post') : ?> selected="selected"<?php endif; ?>>Posts</option>
</select></label></p>
<?php
		endif;
	} /* FWPNHSFeedsConsumer::feedwordpress_post_edit_controls () */

	function feedwordpress_save_edit_controls ($post_id) {
		$from = $_POST['post_type'];

		$to = null;
		if (isset($_POST['fwpnhsfeedsconsumer_post_type'])) :
			$to = $_POST['fwpnhsfeedsconsumer_post_type'];
		endif;
		
		if (!is_null($to) and $to != $from) :
			set_post_field( 'post_type', $to, $post_id);
		endif;
	} /* FWPNHSFeedsConsumer::feedwordpress_save_edit_controls () */
} /* FWPNHSFeedsConsumer */

$nfcAddOn = new FWPNHSFeedsConsumer;

