document.addEventListener('DOMContentLoaded', function () {
    const addToCartButtons = document.querySelectorAll('.add-to-cart');

    addToCartButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const productName = this.dataset.product || 'This item';
            const message = productName + ' added to cart';

            if (typeof window !== 'undefined') {
                window.alert(message);
            }
        });
    });

    /* ── Auto-dismiss flash messages ─────────────────────────────────────── */
    // Success/info messages fade out after 4s, errors stay a bit longer (6s).
    const notices = document.querySelectorAll('.form-notice, .cart-notice, .flash-notice');
    notices.forEach(function (notice) {
        const isError = notice.classList.contains('is-error');
        const duration = isError ? 6000 : 4000;

        // Add fade-out class before removing
        setTimeout(function () {
            notice.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            notice.style.opacity = '0';
            notice.style.transform = 'translateY(-8px)';

            setTimeout(function () {
                notice.remove();
            }, 400);
        }, duration);
    });
});
