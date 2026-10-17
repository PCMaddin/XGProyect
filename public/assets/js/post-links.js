/*
 * Links that change something (build, cancel, kick, delete ...) carry
 * data-post: they are sent as a POST with the CSRF token, so another site
 * cannot trigger them with a plain link. data-confirm asks first; an inline
 * onclick that returns false still stops the link.
 */
document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('a[data-post]') : null;

    if (!link || event.defaultPrevented) {
        return;
    }

    event.preventDefault();

    var question = link.getAttribute('data-confirm');

    if (question && !window.confirm(question)) {
        return;
    }

    var meta = document.querySelector('meta[name="csrf-token"]');
    var form = document.createElement('form');
    var token = document.createElement('input');

    form.method = 'post';
    form.action = link.getAttribute('href');
    token.type = 'hidden';
    token.name = '_token';
    token.value = meta ? meta.getAttribute('content') : '';
    form.appendChild(token);
    document.body.appendChild(form);
    form.submit();
});
