(function() {
    const container = document.getElementById('oauth-status');
    if (!container) return;
    const TARGET_ORIGIN = window.location.origin;

    const status = container.dataset.status;
    const message = container.dataset.message;
    const accountId = container.dataset.accountId;
    const operationStatus = container.dataset.operationStatus;
    const emailAddress = container.dataset.email;

    function notifyOpener(type, data) {
        try {
            if (window.opener && !window.opener.closed) {
                window.opener.postMessage({
                    type: type,
                    email: emailAddress,
                    ...data
                }, TARGET_ORIGIN);
            }
        } catch(e) { console.error('Failed to notify opener:', e); }
    }

    if (status === 'success') {
        notifyOpener('oauth_success', {
            account_id: accountId,
            status: operationStatus
        });

        let seconds = 3;
        const timerEl = document.getElementById('timer');
        
        if (timerEl) {
            const interval = setInterval(() => {
                seconds--;
                if (seconds > 0) {
                    timerEl.textContent = `Closing in ${seconds}s...`;
                } else {
                    clearInterval(interval);
                    timerEl.textContent = "You can close this window now.";
                    window.close();
                }
            }, 1000);
        }
    } else {
        notifyOpener('oauth_error', {
            error: message
        });
    }

    // Manual close button
    const closeBtn = document.getElementById('manual-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            if (status === 'success') {
                notifyOpener('oauth_success', {
                    account_id: accountId,
                    status: operationStatus
                });
            } else {
                // For error, we already sent the message on load, but sending again doesn't hurt
                // or just close
            }
            window.close();
        });
    }
})();
