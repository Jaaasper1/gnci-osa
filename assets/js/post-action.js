// Sends a state-changing action as a POST form (with the CSRF token).
// Used instead of GET links so other websites can't trigger changes.
// Example: postAction({ delete: 12 });
function postAction(params) {
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = window.location.pathname + window.location.search;
    function add(name, value) {
        var i = document.createElement('input');
        i.type = 'hidden';
        i.name = name;
        i.value = value;
        f.appendChild(i);
    }
    add('csrf_token', window.CSRF_TOKEN || '');
    Object.keys(params).forEach(function (k) { add(k, params[k]); });
    document.body.appendChild(f);
    f.submit();
}
