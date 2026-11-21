(() => {
    const status = document.querySelector('.crj-order-check');
    if (!status || typeof ajaxurl !== 'string') {
        return;
    }

    const saveRow = status.closest('.order_actions')?.querySelector('.save_order')?.closest('li');
    if (saveRow) {
        saveRow.before(status);
    }

    if (status.dataset.pending !== '1') {
        return;
    }

    const activity = document.querySelector('#crj-order-activity .inside');
    const poll = async () => {
        try {
            const response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'crj_order_check_status',
                    order_id: status.dataset.order,
                    nonce: status.dataset.nonce
                })
            });

            if (response.status === 403 || response.status === 404) {
                return;
            }
            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    status.textContent = result.data.message;
                    if (!result.data.pending) {
                        if (activity && typeof result.data.activity_html === 'string') {
                            // This is escaped panel markup from the authorized WordPress response.
                            activity.innerHTML = result.data.activity_html;
                        }
                        return;
                    }

                    const activityMessage = activity?.querySelector('.crj-activity-check-message');
                    if (activityMessage) {
                        activityMessage.textContent = result.data.message;
                    }
                }
            }
        } catch {
            // Keep the last known result during intermittent connection failures.
        }
        window.setTimeout(poll, 5000);
    };

    window.setTimeout(poll, 5000);
})();
