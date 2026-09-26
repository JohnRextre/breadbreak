<?php
// Drawer behaviour shared by staff/orders.php and staff/order-history.php.
?>
<script>
(function () {
    var openDrawer = function (id) {
        var drawer = document.getElementById(id);
        if (!drawer) return;
        drawer.hidden = false;
        document.body.classList.add('order-drawer-open');
        var close = drawer.querySelector('.order-drawer-close');
        if (close) close.focus();
    };

    var closeDrawer = function (drawer) {
        drawer.hidden = true;
        if (!document.querySelector('.order-drawer:not([hidden])')) {
            document.body.classList.remove('order-drawer-open');
        }
    };

    document.addEventListener('click', function (event) {
        var opener = event.target.closest('[data-order-drawer]');
        if (opener) {
            event.preventDefault();
            openDrawer(opener.getAttribute('data-order-drawer'));
            return;
        }
        if (event.target.closest('[data-order-drawer-close]')) {
            event.preventDefault();
            var open = document.querySelector('.order-drawer:not([hidden])');
            if (open) closeDrawer(open);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        var open = document.querySelector('.order-drawer:not([hidden])');
        if (open) closeDrawer(open);
    });

    // Cancelling is irreversible enough to warrant a confirmation step.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches('[data-cancel-form]')) return;
        var select = form.querySelector('select[name="reason"]');
        if (!select || !select.value) return;
        if (!window.confirm('Cancel this order?\n\nReason: ' + select.value + '\n\nThis cannot be undone.')) {
            event.preventDefault();
        }
    });
})();
</script>
