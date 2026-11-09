document.addEventListener('DOMContentLoaded', function() {
    var divs = document.querySelectorAll('.issue-comments');
    for (var i = 0; i < divs.length; i++) {
        (function(div) {
            var issue_id = div.getAttribute('issue_id');
            fetch(okapi_base_url + 'services/apiref/issue?issue_id=' + encodeURIComponent(issue_id))
                .then(function(response) {
                    if (!response.ok)
                        throw new Error(response.status);
                    return response.json();
                })
                .then(function(issue) {
                    var link = document.createElement('a');
                    if (issue.comment_count === null) {
                        link.textContent = "Comments";
                    } else {
                        var comments = (issue.comment_count === 1) ? "comment" : "comments";
                        link.textContent = issue.comment_count + " " + comments;
                    }
                    link.setAttribute('href', issue.url);
                    div.appendChild(link);
                    var notice = document.createElement('span');
                    notice.className = 'notice';
                    notice.textContent = "Notice: comments are shared between all OKAPI installations.";
                    div.appendChild(notice);
                })
                .catch(function() {});
        })(divs[i]);
    }

    /**
     * When present on http(s)://opencaching.xx/okapi/some/path page, return "some/path".
     */
    var get_rel_path = function() {
        var myurl = window.location.href;
        var possible_prefixes = [
            okapi_base_url.replace("https://", "http://"),
            okapi_base_url.replace("http://", "https://")
        ];
        for (var i=0; i<possible_prefixes.length; i++) {
            var prefix = possible_prefixes[i];
            if (myurl.substr(0, prefix.length) === prefix) {
                return myurl.substr(prefix.length);
            }
        }
        throw "We're outside of okapi_base_url";
    };

    var switcher = document.getElementById('switcher');
    if (switcher) {
        var current = switcher.querySelector('option[current]');
        switcher.addEventListener('change', function() {
            var current_base_url = current ? current.value : null;
            var new_base_url = switcher.value;
            if (current_base_url !== new_base_url) {
                window.location.href = new_base_url + get_rel_path();
            }
        });
        if (current)
            current.selected = true;
    }
});
