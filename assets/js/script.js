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
});
