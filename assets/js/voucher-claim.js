/**
 * BreadMoments — promoted voucher "Claim" button.
 * Adds the voucher to the signed-in customer's account; falls back to the
 * sign-in page for guests. Shared by the feed (moments.php) and the detail
 * page (moment.php).
 */
(function () {
    'use strict';

    var SIGN_IN_URL = '/BreadBreak/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);

    function showNote(card, message, isError) {
        var note = card.querySelector('[data-claim-note]');
        if (!note) return;
        note.textContent = message;
        note.hidden = false;
        note.classList.toggle('is-error', !!isError);
    }

    function markClaimed(card) {
        var button = card.querySelector('[data-claim-voucher]');
        if (!button) return;
        var replacement = document.createElement('span');
        replacement.className = 'moment-claim-btn is-claimed';
        replacement.setAttribute('aria-label', 'Voucher already claimed');
        replacement.innerHTML = '<i class="fa-solid fa-circle-check"></i> Claimed';
        button.replaceWith(replacement);
    }

    document.querySelectorAll('[data-claim-voucher]').forEach(function (button) {
        button.addEventListener('click', function () {
            var card = button.closest('.moment-card, .moment-detail-actions, .moment-voucher-claim') || document;
            var voucherId = parseInt(button.getAttribute('data-claim-voucher'), 10);
            if (!voucherId) return;

            if (button.dataset.signedIn !== '1') {
                window.location.href = SIGN_IN_URL;
                return;
            }

            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Claiming…';

            fetch('/BreadBreak/api/vouchers/claim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ voucher_id: voucherId })
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        markClaimed(card);
                        showNote(card, data.message, false);
                    } else {
                        button.disabled = false;
                        button.innerHTML = '<i class="fa-solid fa-ticket"></i> Claim';
                        showNote(card, (data && data.message) || 'We could not claim this voucher right now.', true);
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    button.innerHTML = '<i class="fa-solid fa-ticket"></i> Claim';
                    showNote(card, 'Unable to reach the server. Please try again.', true);
                });
        });
    });
})();