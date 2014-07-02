(function($) {
	function fwpNHSNotificationUpdateLabel (N) {
		var txt = ' <em class="fwp-nhs-post-update">UPDATE';
		if (N > 1) {
			txt += ' ('+N.toString()+')';
		} /* if */
		txt += '</em>';
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
						$(this).css('background-color', '#c0ffff');
						$(this).find('.post-title .row-title').append(fwpNHSNotificationUpdateLabel(postN));
						
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
			
			$.ajax({
				type: "POST",
				url: ajaxurl,
				data: {
					action: "fwp_nhs_review_queue_seen",
					seen: seenIt
				}
			})
			.done(function (response) { /* NOOP */ })
			.fail(function (response) { /* NOOP */ });
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

