(function () {
    if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal || !window.fetch) {
        return;
    }

    document.querySelectorAll('.crj-refund').forEach((details, index) => {
        const form = details.querySelector('form');
        const heading = form?.querySelector('h2');
        const actions = form?.querySelector('.crj-refund-actions');
        const summary = details.querySelector('summary');
        if (!heading || !actions || !summary) {
            return;
        }

        const opener = document.createElement('button');
        opener.type = 'button';
        opener.className = 'crj-refund-button';
        const icon = document.createElement('span');
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '↻';
        opener.append(icon, ' ', summary.textContent);
        opener.setAttribute('aria-haspopup', 'dialog');

        const dialog = document.createElement('dialog');
        dialog.className = 'crj-refund-dialog';
        heading.id = 'crj-refund-dialog-title-' + index;
        dialog.setAttribute('aria-labelledby', heading.id);

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'crj-refund-cancel';
        cancel.textContent = details.dataset.cancel;
        actions.append(cancel);

        const result = document.createElement('section');
        result.className = 'crj-refund-result';
        result.hidden = true;
        result.innerHTML = '<span class="crj-refund-result-icon" aria-hidden="true"></span><h2 tabindex="-1"></h2>' +
            '<dl><div><dt>Transaction</dt><dd data-reference></dd></div>' +
            '<div><dt>Amount</dt><dd data-amount></dd></div>' +
            '<div><dt>Message</dt><dd data-message></dd></div></dl>' +
            '<button type="button" class="crj-refund-cancel">Close</button>';
        const resultTitle = result.querySelector('h2');
        resultTitle.id = heading.id + '-result';
        const close = result.querySelector('button');
        dialog.append(form, result);
        details.replaceWith(opener, dialog);

        let submitting = false;
        let refresh = false;
        opener.addEventListener('click', () => {
            dialog.showModal();
            (form.querySelector('input[name="crj_amount"]') || cancel).focus();
        });
        cancel.addEventListener('click', () => dialog.close());
        close.addEventListener('click', () => dialog.close());
        dialog.addEventListener('cancel', (event) => {
            if (submitting) {
                event.preventDefault();
            }
        });
        dialog.addEventListener('click', (event) => {
            if (submitting || event.target !== dialog) {
                return;
            }
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right ||
                event.clientY < bounds.top || event.clientY > bounds.bottom) {
                dialog.close();
            }
        });
        dialog.addEventListener('close', () => {
            if (refresh) {
                window.location.reload();
                return;
            }
            form.reset();
            form.hidden = false;
            result.hidden = true;
            dialog.setAttribute('aria-labelledby', heading.id);
            opener.focus();
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitting) {
                return;
            }
            submitting = true;
            const data = new FormData(form);
            if (event.submitter?.name) {
                data.set(event.submitter.name, event.submitter.value);
            }
            const value = data.get('crj_amount');
            const amount = typeof value === 'string' && value !== '' ? 'PKR ' + Number(value).toFixed(2) : '—';
            const reference = form.querySelector('.crj-refund-reference')?.textContent || '—';
            form.hidden = true;
            result.hidden = false;
            result.dataset.state = 'working';
            result.setAttribute('aria-busy', 'true');
            result.querySelector('.crj-refund-result-icon').textContent = '⏳';
            resultTitle.textContent = details.dataset.processing;
            result.querySelector('[data-reference]').textContent = reference;
            result.querySelector('[data-amount]').textContent = amount;
            result.querySelector('[data-message]').textContent = 'Waiting for JazzCash confirmation.';
            close.hidden = true;
            dialog.setAttribute('aria-labelledby', resultTitle.id);
            resultTitle.focus();

            let reply;
            try {
                const response = await fetch(details.dataset.ajaxUrl || form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams(data)
                });
                const body = await response.text();
                try {
                    reply = JSON.parse(body);
                } catch {
                    reply = null;
                }
                if (typeof reply?.success !== 'boolean' || typeof reply.message !== 'string') {
                    const token = body.trim();
                    const wordpressCode = token === '0' || token === '-1';
                    reply = {
                        success: false,
                        message: response.redirected ? 'WordPress redirected the refund request. Sign in again before retrying.' :
                            token === '-1' ? 'WordPress rejected the refund security check.' :
                            token === '0' ? 'WordPress did not handle the refund request.' :
                            'The server did not return a refund result. Check JazzCash before trying again.',
                        code: wordpressCode ? token : 'HTTP ' + response.status,
                        refresh: true
                    };
                }
            } catch {
                reply = { success: false, message: 'The refund request could not reach the server or the connection was interrupted. Check JazzCash before trying again.', refresh: true };
            }
            refresh = reply.refresh === true;
            result.dataset.state = reply.success ? 'success' : 'error';
            result.querySelector('.crj-refund-result-icon').textContent = reply.success ? '✓' : '×';
            resultTitle.textContent = reply.success ? 'Refund Complete' : 'Refund Failed';
            result.querySelector('[data-message]').textContent = reply.message + (reply.code ? ' (Code: ' + reply.code + ')' : '');
            result.removeAttribute('aria-busy');
            close.hidden = false;
            submitting = false;
            resultTitle.focus();
        });
    });
}());
