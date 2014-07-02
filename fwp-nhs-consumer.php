<?php
/*
Plugin Name: FWP+: NHS Feeds Consumer
Plugin URI: https://github.com/radgeek/fwp-nhs-consumer
Description: A FeedWordPress filter that fetches full content and locally caches images from UK NHS feeds you syndicate, and adds additional options to help with workflow in processing content from NHS feeds.
Author: Charles Johnson
Version: 2014.0702
Author URI: http://feedwordpress.radgeek.com/
*/

define('FWPNFC_CACHE_IMAGES_DEFAULT', 'no');
define('FWPNFC_GRAB_FULL_HTML_DEFAULT', 'no');
define('FWPNFC_PROCESS_POSTS_MAX', 10);

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
	private $post;
	
	function __construct () {
		$this->name = strtolower(get_class($this));
		add_action('init', array($this, 'init'));

		add_action('feedwordpress_post_edit_controls', array($this, 'feedwordpress_post_edit_controls'), 10, 1);
		add_action('feedwordpress_save_edit_controls', array($this, 'feedwordpress_save_edit_controls'), 10, 1);
		add_action('admin_menu', array($this, 'admin_init'));
		add_action('update_syndicated_item', array($this, 'syndicated_item_revision'), 10, 2);
		add_action('post_syndicated_item', array($this, 'syndicated_item_revision'), 10, 2);
		add_filter('syndicated_post_fix_revision_meta', array($this, 'syndicated_post_fix_revision_meta'), 10, 2);
		add_filter('feedwordpress_update_complete', array($this, 'process_full_html'), -2000, 1);

		add_filter('feedwordpress_diagnostics', array($this, 'diagnostics'), 10, 2);
		add_action('feedwordpress_admin_page_syndication_meta_boxes', array($this, 'add_queue_box'));

		add_action('admin_print_scripts', array($this, 'admin_print_scripts'));
		add_action('wp_ajax_fwp_nhs_review_queue_count', array($this, 'wp_ajax_fwp_nhs_review_queue_count'));
		add_action('wp_ajax_fwp_nhs_review_queue_seen', array($this, 'wp_ajax_fwp_nhs_review_queue_seen'));
		add_action('feedwordpress_admin_page_feeds_meta_boxes', array(&$this, 'add_settings_box'));
		add_action('feedwordpress_admin_page_feeds_save', array(&$this, 'save_settings'), 10, 2);

	} /* FWPNHSFeedsConsumer::__construct () */

	function syndicated_post_fix_revision_meta ($revision_id, $post) {

		# How we know that an NHS feed is providing full content
		# somewhere else, off the feed: (1) there's no body HTML on the
		# entry element (meaning we're only given a short text summary
		
		if (is_null($post->content(array("full only"=>true)))) :

			# (2) there's a link[@rel="self"] element pointing to
			# the HTML content for the full story.
			$linkElements = $post->entry->get_item_tags(SIMPLEPIE_NAMESPACE_ATOM_10, 'link');
			if (count($linkElements) > 0) :
				foreach ($linkElements as $link) :
					$rel = NULL;
					$type = NULL;
					$href = NULL;
					foreach (array('', SIMPLEPIE_NAMESPACE_ATOM_10) as $ns) :
						if (isset($link['attribs'][$ns])) :
							if (isset($link['attribs'][$ns]['rel'])) :
								$rel = $link['attribs'][$ns]['rel'];
							endif;

							if (isset($link['attribs'][$ns]['type'])) :
								$type = $link['attribs'][$ns]['type'];
							endif;

							if (isset($link['attribs'][$ns]['href'])) :
								$href = $link['attribs'][$ns]['href'];
							endif;
						endif;
					endforeach;
					
					if (
						$rel=='self' and $type='text/html'
						and ('yes'==$post->link->setting('grab full html', 'grab_full_html', FWPNFC_GRAB_FULL_HTML_DEFAULT))
					) :
						// This is the link to full HTML
						// We need to save it for future
						// use, along with the revision
						// ID that it's relevant to, and
						// a note indicating the
						// revision corresponding to the
						// current content.
						$post_id = $post->wp_id();
						update_post_meta($post_id, "_syndicated_revision_full_html", array("id" => $revision_id, "url" => $href));
						
						if (
							$post->freshness() > 0
						) :
							update_post_meta($post_id, '_syndicated_revision_visible', $revision_id);
						endif;
					endif;
				endforeach;
						
			endif;
		endif;
	} /* FWPNHSFeedsConsumer::syndicated_post_fix_revision_meta () */
	
	function process_full_html ($delta) {
		global $post, $wpdb;

		// Let's do this.
		$q = $this->query_syndicated_revision_full_html(array(
		"posts_per_page" => $this->process_posts_max(),
		));
		
		while ($q->have_posts()) : $q->the_post();
			$this->post = $post;
			$zapit = false;
			$captured_from = array();
			
			$failed_from = get_post_custom_values('html capture failed');
			$urls = get_post_meta($post->ID, '_syndicated_revision_full_html', /*single=*/ false);
			$source = get_syndication_feed_object($post->ID);

			if (
				(count($urls) > 0)
				and !!$urls[0]
				and ('yes' == $source->setting('grab full html', 'grab_full_html', FWPNFC_GRAB_FULL_HTML_DEFAULT))
			) :
			
				foreach ($urls as $set) :
					$revision_id = $set['id'];
					$url = $set['url'];
					$rev = get_post($revision_id);

					$post_content = $rev->post_content;

					if ($url) :
						$grab = $this->grab_text($url, $post->ID);
						$post_content = $grab['text'];
						$cats = $grab['cats'];
						
						// syndicated post object
						$oPost = new FeedWordPressLocalPost($post->ID);
						$link = $oPost->feed();
						$catIds = array();
						if ($link and $link->found()) :
							$uf = $link->setting("unfamiliar category", "unfamiliar_category", "create:category");
							$catIds = $link->category_ids($oPost, $cats, $uf, array("singleton" => false, "filters" => true));
						endif;
						
						foreach ($catIds as $tax => $termset) :
							if (count($termset) > 0) :
								$res =	wp_set_object_terms($post->ID, $termset, $tax, /*append=*/ true); 
							endif;
						endforeach;
						
						$ok = false;
						if (is_string($post_content)) :
							$rev->post_content = $post_content;
							
							// Save as a revision of the existing post.
							$ok = $this->insert_revision($rev, $post);
							
							// Fire an action indicating that we've updated
							do_action('update_syndicated_item', $post->ID, $oPost);
						endif;
						
						if ($ok) :
							$zapit = true;
							$captured_from[] = time()." ".$url." ".substr(FeedWordPress::val($post_content),0,128);
						else :
							$failed_from[] = time()." ".$url." ".substr(FeedWordPress::val($post_content),0,128);

							if (count($failed_from) > 3) : // strikes and yr out
								$zapit = true;
							endif;
						endif;
					endif;
				endforeach;
				
			else :
				
				$zapit = true;
				
			endif;

			if ($zapit) :
				delete_post_meta($post->ID, '_syndicated_revision_full_html');
			endif;
			if (count($captured_from) > 0) :
				foreach ($captured_from as $url) :
					add_post_meta($post->ID, 'html captured from', $url,
					/*unique=*/ false);
				endforeach;
			endif;
			if (count($failed_from) > 0) :
				delete_post_meta($post->ID, 'html capture failed');
				foreach ($failed_from as $url) :
					add_post_meta($post->ID, 'html capture failed', $url, /*unique=*/ false);
				endforeach;
			endif;
		endwhile;
		
	} /* FWPNHSFeedsConsumer::process_full_html () */
	
	function grab_text ($url, $to, $args = array()) {
		$args = wp_parse_args($args, array( // Default values
		'source' => NULL,
		));
		if (is_null($args['source'])) :
			$args['source'] = get_syndication_feed_object($to);
		endif;
		
		$source = $args['source'];
		
		$text = NULL;
		$postCats = NULL;

		# Fetch the URI
		$headers['Connection'] = 'close';
		$headers['Referer'] = get_permalink($to);
		
		if (is_callable(array('FeedWordPress', 'fetch_timeout'))) :
			$timeout = FeedWordPress::fetch_timeout();
		elseif (defined('FEEDWORDPRESS_FETCH_TIME_OUT')) :
			$timeout = FEEDWORDPRESS_FETCH_TIME_OUT;
		elseif (defined('FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT')) :
			$timeout = FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT;
		else :
			$timeout = 60;
		endif;
		
		FeedWordPress::diagnostic('nfc:capture:http', "HTTP &raquo;&raquo; GET [$url] (".__METHOD__.")");
		$http = wp_remote_request($url, array(
			'headers' => $headers,
			'timeout' => $timeout,
			'authentication' => $source->authentication_method(),
			'username' => $source->username(),
			'password' => $source->password(),
		));

		if (
			!is_wp_error($http)
			and isset($http['response'])
			and ($http['response']['code'] == 200) // OK
		) :

			# Get the MIME type from the Content-Type header
			$mimetype = NULL;
			if (isset($http['headers']['content-type'])) :
				$split = explode(";", $http['headers']['content-type'], 2);
				$mimetype = $split[0];
				$params = (isset($split[1]) ? $split[1] : null);
			endif;
			
			$data = $http['body'];
			$text = $data;

			if (!is_null($data)) :
				switch ($mimetype) :
				case 'text/html' :
				case 'application/xhtml+xml' :
					// For NHS feeds, we have to parse out
					// the contents of a div[@class="content"]
					//
					// Except for the tracking pixel img,
					// which they insist you include, but
					// which they do not bother to put down
					// in the .content section everyone's
					// going to chop out.
					$dom = new DOMDocument;
					$dom->loadHTML($data);
					
					$innerHTML = '';
					
					$postHTML = new DOMDocument;
					$dd = $dom->getElementsByTagName('img');
					for ($i = 0; $i < $dd->length; $i++) :
						$node = $dd->item($i);
						$postHTML->appendChild($postHTML->importNode($node,true));
					endfor;
					
					$dd = $dom->getElementsByTagName('div');
					for ($i = 0; $i < $dd->length; $i++) :
						$node = $dd->item($i);
						if ('content'==$node->getAttribute('class')) :
							$children = $node->childNodes;
							$frozen = false;
							foreach ($children as $child) :
								// H2 divides up the major sections. We want to break at Related Articles,
								// which contains a bunch of internal links that users can't access
								if ('h2'==$child->nodeName) :
									if (preg_match('/^Related\s+Articles/i', trim($child->textContent))) :
										$frozen = true;
									endif;
								endif;
								
								if (!$frozen) :
									$postHTML->appendChild($postHTML->importNode($child,true));
								endif;
							endforeach;
						endif;
					endfor;
					$innerHTML .= $postHTML->saveHTML();
					
					// Now let's try to get the article
					// categories out of the dl/dt/dd struct
					$dt = $dom->getElementsByTagName('dt');
					for ($i = 0; $i < $dt->length; $i++) :
						$node = $dt->item($i);
					
						$txt = $node->textContent;
						
						if (preg_match('/^\s*(Article.s\s+)?categor(ies|y)\s*$/i', $txt)) :
							$sib = $node->nextSibling;
							while (!is_null($sib) and $sib->nodeName != 'dt') :
								if ('dd' == $sib->nodeName) :
									if (is_null($postCats)) : $postCats = array(); endif;
									$postCats[] = $sib->textContent;
								endif;
								$sib = $sib->nextSibling;
							endwhile;
						endif;
					endfor;
					
					if (strlen($innerHTML) == 0) :
						if (preg_match(':<body (\s+[^>]*)? > (.+) </body>:six', $data, $ref)) :
							$innerHTML = $ref[2];
						else :
							$innerHTML = $data; // ugh.
						endif;
					endif;
										// Make sure that nothing from the NHS's
					// end leaks out to compromise our API
					// key. It is appalling that I should
					// have to do this on the client side
					// for what is supposedly a syndication
					// service.
					$innerHTML = preg_replace('|[?&]apikey=[^&"]+|i', '', $innerHTML);

					$text = '<div class="nhs-full-text">'.$innerHTML.'</div>';

				endswitch;
			endif;
		else :
			FeedWordPress::diagnostic('nfc:capture:http', "&laquo;&laquo; ERROR [$url] (".__METHOD__."): ".FeedWordPress::val($http));
		endif;
		return array("text" => $text, "cats" => $postCats);
	} /* FWPNHSFeedsConsumer::grab_text () */
	
	function insert_revision ($rev, $post) {
		$success = true; // Innocent until proven guilty
		
		if (strlen(trim($rev->post_content)) > 0) :
	
			// This is a ridiculous fucking kludge necessitated by WordPress
			// munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
	
			$this->post = $rev;
			
			// Quick! Dress in a current post costume
			$rev->ID = $rev->post_parent;
			$rev->post_type = $post->post_type;
			$rev->post_status = $post->post_status;
			$rev->post_parent = 0;
			
			$new_rev_id = _wp_put_post_revision($rev, /*autosave=*/ false);
			
			if (!is_wp_error($new_rev_id)) :
			// Now check to see whether the revision we just revised
			// was the current revision. . .

				if ($post->post_modified_gmt == $rev->post_modified_gmt) :
					wp_restore_post_revision($new_rev_id);
				endif;

			else :
				$success = false;
			endif;
						
			// Turn off ridiculous fucking kludge
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
		else :
			$success = false;
		endif;
		return $success;
	} /* FWPNHSFeedsConsumer::insert_revision () */

	function fix_revision_meta ($revision_id) {
		global $wpdb;
		
		$post_author = (int) $this->post->post_author;
		
		$revision_id = (int) $revision_id;
		$wpdb->query("
		UPDATE $wpdb->posts
		SET post_author={$this->post->post_author}
		WHERE post_type = 'revision' AND ID='$revision_id'
		");
	} /* FWPNHSFeedsConsumer::fix_revision_meta () */

	function syndicated_item_revision ($id, $post) {
		$notify_revisions = $post->link->setting('fwpnfc notify revisions', 'fwpnfc_notify_revisions', true);

		if ($notify_revisions) :
			$soPost = (object) $post->post;
			$post_type = $soPost->post_type;
			$map = get_option('fwpnfc_to_review_map', array());
			
			if (!is_array($map)) : $map = array(); endif;
			if (!isset($map[$post_type])) : $map[$post_type] = array(); endif;
			if (!isset($map[$post_type][$id])) : $map[$post_type][$id] = 0; endif;
			
			// Increment revisions-since-last-seen counter
			$map[$post_type][$id] += 1;
			
			update_option('fwpnfc_to_review_map', $map);
		endif;
	} /* FWPNHSFeedsConsumer::syndicated_item_revision () */
	
	function init () {
		$taxonomies = get_object_taxonomies('post', 'names');
		
		$reviewQueueLabel = __('Review Queue');
		
		register_post_type('syndicatedreview', array(
			'labels' => array(
				'name' => 'Review Items',
				'singular_name' => 'Review Item',
				'menu_name' => $reviewQueueLabel,
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

	function admin_init () {
		global $fwpnfc_path;

		add_filter('posts_search', array($this, 'admin_posts_search'), 10, 2);
		add_filter('posts_join', array($this, 'admin_posts_join'), 10, 2);

		wp_register_style('fwp-nhs-elements', WP_PLUGIN_URL.'/'.$fwpnfc_path.'/fwp-nhs-elements.css');
		wp_enqueue_style('fwp-nhs-elements');
	}
	
	function admin_posts_search ($search, $q) {
		if (is_string($search) and strlen($search) > 0) :
			$s = $q->query_vars['s'];
			if (is_string($s) and strlen($s) > 0) :
				$search = preg_replace('/^\s+AND\s+/i', '', $search);
				$search .= " OR (nhsm1.meta_value LIKE '%".esc_sql($s)."%') ";
				$search = ' AND ('.$search.')';
			endif;
		endif;
		return $search;
	}
	
	function admin_posts_join ($join, $q) {
		global $wpdb;
		
		$s = $q->query_vars['s'];
		if (is_string($s) and strlen($s) > 0) :
			$join .= " LEFT OUTER JOIN {$wpdb->postmeta} AS nhsm1 ON (nhsm1.meta_key='syndication_permalink' AND nhsm1.post_id={$wpdb->posts}.ID)";
		endif;
		
		return $join;
	}
	
	function process_posts_max () {
		$max = get_option('fwpnfc_process_posts_max', FWPNFC_PROCESS_POSTS_MAX);
		if (!is_numeric($max)) :
			$max = FWPNFC_PROCESS_POSTS_MAX;
		endif;
		return $max;
	} /* FWPNHSFeedsConsumer::process_posts_max () */
	

	////////////////////////////////////////////////////////////////////////////
	// SETTINGS UI /////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////
	
	function wp_ajax_fwp_nhs_review_queue_count () {
		$mapToReview = get_option('fwpnfc_to_review_map', array());

		$out = array();
		
		$out['syndicatedreviewnotices'] = null;
		if ($nToReview > 0) :
			$out['syndicatedreviewnotices'] = sprintf('+%d', $nToReview);
		endif;

		if (count($mapToReview) > 0) :
			$out['revisionsnotices'] = $mapToReview;
		endif;
		
		header ("Content-Type: application/json");
		echo json_encode($out);
		
		// This is an AJAX request, so close it out thus.
		die;
	} /* FWPNHSFeedsConsumer::wp_ajax_fwp_nhs_review_queue_count () */
	
	function wp_ajax_fwp_nhs_review_queue_seen () {
		$mapToReview = get_option('fwpnfc_to_review_map', array());

		foreach (MyPHP::post('seen', array()) as $what => $which) :
			foreach ($which as $post_id => $status) :
				$post_id = intval($post_id);
				if (isset($mapToReview[$what][$post_id])) :
					unset($mapToReview[$what][$post_id]);
				endif;
			endforeach;
		endforeach; 	

		// Now write back the version with the updates taken out.
		update_option('fwpnfc_to_review_map', $mapToReview);
		
		header ("Content-Type: application/json");
		echo json_encode($mapToReview);
		
		// This is an AJAX reuqest, so close it out thus.
		die;
	} /* FWPNHSFeedsConsumer::wp_ajax_fwp_nhs_review_queue_seen () */
	
	function admin_print_scripts () {
		global $fwpnfc_path;
		
		wp_register_script('fwp-nhs-elements', WP_PLUGIN_URL.'/'.$fwpnfc_path.'/fwp-nhs-elements.js');
		wp_enqueue_script('fwp-nhs-elements');
	}
	
	function diagnostics ($diag, $page) {
		$diag['NHS Feeds Consumer']['nfc:capture'] = 'as syndicated text is captured or rejected for local copies';
		$diag['NHS Feeds Consumer']['nfc:capture:http'] = 'as the HTTP GET request is sent to capture a local copy of a syndicated story';
		$diag['NHS Feeds Consumer']['nfc:capture:reject'] = 'when a captured story is rejected instead of being kept as a local copy';
		return $diag;
	} /* FWPNHSFeedsConsumer::diagnostics () */

	function add_settings_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_box",
			/*title=*/ __("NHS Feeds API Features"),
			/*callback=*/ array(&$this, 'display_settings'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* FWPNHSFeedsConsumer::add_settings_box() */
	
	function display_settings ($page, $box = NULL) {
		$grabFullHTMLSelector = array(
		"no" => __("<strong>Use contents from feed:</strong> Keep the contents or excerpt provided by the feed"),
		"yes" => __("<strong>Retrieve full text from web:</strong> Attempt to retrieve full text from <code>http://example.com/page/1</code>, using the included link"),
		);
		$gfhParams = array(
		'input-name' => "nfc_grab_full_html",
		"setting-default" => NULL,
		"global-setting-default" => FWPNFC_GRAB_FULL_HTML_DEFAULT,
		"default-input-value" => 'default',
		);
		$notify_revisions = $page->setting('fwpnfc notify revisions', true);
?>
		<style type="text/css">
		ul.options ul.suboptions { margin: 10px 20px; }
		ul.options ul.suboptions li { display: inline; margin-right: 1.5em; }
		</style>

		<table class="edit-form narrow">
		<tr><th scope="row"><?php _e('UI Notifications for Updates:'); ?></th>
		<td><p><label><select size="1" name="fwpnfc_notify_revisions">
		  <option value="yes"<?php if ($notify_revisions) : ?> selected="selected"<?php endif; ?>>Keep track</option>
		  <option value="no"<?php if (!$notify_revisions) : ?> selected="selected"<?php endif; ?>>Don't keep track<topion>
		</select> of new updates and revisions appearing on your feed<?php if ($page->for_default_settings()) : ?>s<?php endif; ?>
		and display a UI notification when updates come in for you to review.</label></p></td>
		
		<tr><th scope="row"><?php _e('Retrieve full HTML:'); ?></th>
		<td><p>When a syndicated post includes a short text description and a
		link to the full story at <code>http://example.com/page/1</code>,
		<?php
			$page->setting_radio_control(
				'grab full html', 'grab_full_html',
				$grabFullHTMLSelector, $gfhParams
			);
		?></td></tr>
		
		<?php
		if ($page->for_default_settings()) :
			$value = $this->process_posts_max();
		?>
		<tr><th scope="row"><?php _e('Queued web requests:'); ?></th>
		<td><p>Process <input type="number" min="-1" step="1" size="4" value="<?php print esc_attr($value); ?>" name="fwpnfc_process_posts_max" /> queued requests per update cycle.</p>
		<div class="setting-description">If you start seeing long delays between when posts are syndicated and when their full text is retrieved &#8212; or if posts start piling up in the NHS Feed API Post Processing Queue &#8212; you may need to adjust this setting higher. If you start noticing that update processes take too long to complete, you may need to adjust this setting lower. Use a value of <code>-1</code> to force NHS Feeds Consumer to process <em>all</em> queued requests during <em>every</em> update cycle.</div>
		</td></tr>
		<?php endif; ?>
		
		</table>
<?php
	} /* FWPNHSFeedsConsumer::display_settings () */
	
	function save_settings ($params, $page) {
		if (isset($params['nfc_grab_full_html'])) :
			$notify_revisions = (isset($params['fwpnfc_notify_revisions']) and 'yes'==$params['fwpnfc_notify_revisions']);
			$page->update_setting('fwpnfc notify revisions', $notify_revisions);
			
			$page->update_setting('grab full html', $params['nfc_grab_full_html']);
	
			if ($page->for_default_settings()) :
				update_option('fwpnfc_process_posts_max', $params['fwpnfc_process_posts_max']);

			endif;

		endif;
		
	} /* FWPNHSFeedsConsumer::save_settings () */

	function add_queue_box ($page) {
		add_meta_box(
			/*id=*/ "feedwordpress_{$this->name}_queue_box",
			/*title=*/ __("NHS Feed API Post Processing Queue"),
			/*callback=*/ array(&$this, 'display_queue'),
			/*page=*/ $page->meta_box_context(),
			/*context=*/ $page->meta_box_context()
		);
	} /* FWPReutersNewsconsumer::add_queue_box() */

	function query_syndicated_revision_full_html ($params = array()) {
		$params = wp_parse_args($params, array(
		'post_type' => get_post_types( ), // Can't use "any," which excludes private post types.
		'meta_key' => '_syndicated_revision_full_html',
		'posts_per_page' => -1,
		'order' => 'ASC',
		));
		return new WP_Query($params);
	}
	
	function display_queue ($page, $box = NULL) {
		$posts = array(); $urls = array();
		$q = $this->query_syndicated_revision_full_html();
		while ($q->have_posts()) : $q->the_post();
			$m = get_post_meta($q->post->ID, '_syndicated_revision_full_html', /*single=*/ true);
			$posts[$m['id']] = $q->post;
			$urls[$m['id']][] = $m['url'];
		endwhile;
?>
<table style="width: 100%">
<thead>
<tr>
<th>Status</th>
<th>Post</th>
<th>Date</th>
<th>URL</th>
</tr>
</thead>
<tbody>
<?php foreach ($posts as $ID => $p) : ?>
<tr>
<td><?php print ucfirst($p->post_status); ?></td>
<td><?php print $p->post_title; ?></td>
<td><?php print $p->post_date; ?></td>
<td><?php foreach ($urls[$ID] as $url) :
	print '<a href="'.$url.'">'.feedwordpress_display_url($url)."</a>";
endforeach; ?></td>
</tr>
<?php endforeach; ?>
</body>
</table>
<?php
	}

} /* FWPNHSFeedsConsumer */

$nfcAddOn = new FWPNHSFeedsConsumer;

