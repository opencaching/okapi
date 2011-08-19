$(function() {
	$('.issue-comments').each(function() {
		var div = $(this);
		var issue_id = div.attr('issue_id');
		$.ajax({
			type: 'GET',
			dataType: 'json',
			url: okapi_base_url + 'services/apiref/issue',
			data: {
				'issue_id': issue_id
			},
			success: function(issue)
			{
				var comments = (issue.comment_count == 1) ? "comment" : "comments";
				var link = $("<a>" + issue.comment_count + " " + comments + "</a>");
				link.attr('href', issue.url);
				div.append(link);
			}
		});
	});
});