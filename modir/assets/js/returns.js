document.addEventListener('DOMContentLoaded', function () {
    var select = document.getElementById('modir-return-order');
    if (!select) {
        return;
    }

    function updateItems() {
        var groups = document.querySelectorAll('.modir-return-items-group');
        groups.forEach(function (el) {
            el.style.display = (el.getAttribute('data-order') === select.value) ? 'block' : 'none';
        });
    }

    select.addEventListener('change', updateItems);
    updateItems();
});
