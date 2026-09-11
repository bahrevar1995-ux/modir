document.addEventListener('DOMContentLoaded', function () {
    var btn = document.querySelector('.modir-tracking-copy-btn');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var text = btn.getAttribute('data-copy');
        navigator.clipboard.writeText(text).then(function () {
            var original = btn.textContent;
            btn.textContent = 'کپی شد';
            btn.classList.add('is-copied');
            setTimeout(function () {
                btn.textContent = original;
                btn.classList.remove('is-copied');
            }, 1500);
        });
    });
});
