function fwpNHSNotificationUpdateLabelRemove (wot) {
	var post = jQuery(wot).attr('id').match(/^fwp-nhs-remove-update-label-(.+)-([0-9]+)$/, "$0\n$1");
	var post_type = post[1];
	var post_id = post[2];
	var seenIt = {};
	
	seenIt[post_type] = {};
	seenIt[post_type][post_id] = true;
	
	jQuery.ajax({
		type: "POST",
		url: ajaxurl,
		data: {
			action: "fwp_nhs_review_queue_seen",
			seen: seenIt
		}
	})
	.done(function (response) {

		for (var post_type in response[0]) {
			for (var post_id in response[0][post_type]) {
				// Highlight it.
				jQuery('.wp-list-table.posts #post-'+post_id).removeClass('fwp-nhs-has-update-notice');
				jQuery('#fwp-nhs-remove-update-tag-'+post_type+'-'+post_id).remove();
			} /* for */
		} /* for */
	})
	.fail(function (response) { /* NOOP */ });

	return false;
}

(function($) {
	
	function fwpNHSNotificationUpdateLabel (N, id, post_type) {
		var txt = ' <em id="fwp-nhs-remove-update-tag-'+post_type+'-'+id.toString()+'" class="fwp-nhs-post-update">UPDATE';
		if (N > 1) {
			txt += ' ('+N.toString()+')';
		} /* if */
		txt += '| <a href="#" id="fwp-nhs-remove-update-label-'+post_type+'-'+id.toString()+'" onclick="fwpNHSNotificationUpdateLabelRemove(this); return false;">×</a></em>';
		return txt;
	}
	function fwpNHSNotificationIcons (response) {
		// Did we get back a list of IDs that have undergone a revision
		// from FeedWordPress syndicating a new post or an update?
		if (typeof(response.revisionsnotices) != 'undefined') {
			var seenIt = {};
			for (var post_type in response.revisionsnotices) {
				var N = 0;
				for (var i in response.revisionsnotices[post_type]) {
					var postN = response.revisionsnotices[post_type][i];
					N += postN;

					$( '.wp-list-table.posts' ).find('#post-' + i).each( function () {
						// Highlight it.
						$(this).addClass('fwp-nhs-has-update-notice');
						$(fwpNHSNotificationUpdateLabel(postN, i, post_type)).insertAfter($(this).find('.post-title .row-title'));
						
						// Remove count from total.
						N -= postN;
						
						if (!(post_type in seenIt)) {
							seenIt[post_type] = {};
						}
						seenIt[post_type][i] = true;
					} );

				} /* for */
				
				var suffix = '';
				if (post_type != 'post') {
					suffix = '-' + post_type;
				}
				if (N > 0) {
					var cap = N.toString();
					if (N > 20) {
						cap = '20+';
					}
					$('#menu-posts'+suffix).append('<div class="fwp-nhs-notification-icon">'+cap+'</div>');
				}
			} /* for */
			
		} /* if */
	} /* function fwpNHSNotificationIcons () */

	$(document).ready(function () {
		// Make sure we are in a normal wp-admin interface screen
		$('#menu-posts').each( function () {
			$.ajax({
				type: "GET",
				url: ajaxurl,
				data: {
					action: "fwp_nhs_review_queue_count"
				}
			})
			.done(fwpNHSNotificationIcons)
			.fail(function (response) { /* NOOP */ });
		});
				
	})

})(jQuery);

