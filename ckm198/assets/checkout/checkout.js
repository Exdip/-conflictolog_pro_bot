document.addEventListener('click', async function (event) {
    const button = event.target.closest('.ckm-checkout [data-ckm-payment-game], .ckm-checkout [data-ckm-payment-games]');
    if (!button || button.disabled) return;
    const status = button.closest('.ckm-checkout__card').querySelector('.ckm-checkout__status');
    const label = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Подготовка оплаты…';
    status.hidden = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
        const payload = {action: 'ckm_create_payment', nonce: CKM_CHECKOUT.nonce};
        if (button.dataset.ckmPaymentGames) payload.games = button.dataset.ckmPaymentGames;
        else payload.game = button.dataset.ckmPaymentGame;
        if (button.dataset.ckmRenew === '1') payload.renew = '1';
        if (CKM_CHECKOUT.preview_user) payload.ckm_preview_user = String(CKM_CHECKOUT.preview_user);
        if (CKM_CHECKOUT.preview_nonce) payload.ckm_preview_nonce = String(CKM_CHECKOUT.preview_nonce);
        const response = await fetch(CKM_CHECKOUT.ajax_url, {
            method: 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(payload)
        });
        const result = await response.json();
        if (!response.ok || !result.success || !result.data || !result.data.url) {
            throw new Error(result.data && result.data.message || 'Не удалось начать оплату. Повторите попытку.');
        }
        const url = new URL(result.data.url, window.location.href);
        if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Некорректная ссылка оплаты.');
        if (result.data.already_owned) {
            window.location.assign(url.href);
            return;
        }
        try {
            const selected = String(payload.games || payload.game || '').split(',').map(v => v.trim()).filter(Boolean);
            const base = new URL('/ckm-organizer/', window.location.origin);
            base.searchParams.set('view', 'library');
            base.searchParams.set('paid', '1');
            if (selected.length === 1) base.searchParams.set('format', selected[0]);
            const checkpoint = new URL('/ckm-organizer/', window.location.origin);
            checkpoint.searchParams.set('view', 'payment');
            checkpoint.searchParams.set('payment_return', '1');
            if (selected.length) checkpoint.searchParams.set('games', selected.join(','));
            // Preserve a signed administrator preview through the round-trip to
            // the payment provider. Without these parameters a return to
            // /wp-admin/ loses organizer 2222 and can land on the WP dashboard.
            if (CKM_CHECKOUT.preview_user && CKM_CHECKOUT.preview_nonce) {
                [base, checkpoint].forEach(function (u) {
                    u.searchParams.set('ckm_preview_user', String(CKM_CHECKOUT.preview_user));
                    u.searchParams.set('ckm_preview_nonce', String(CKM_CHECKOUT.preview_nonce));
                });
            }
            window.localStorage.setItem('ckmqpPostpayReturn', JSON.stringify({
                // Safe landing: payment page lists the newly paid games and avoids
                // wp-admin/dashboard redirect loops. The library URL is kept only
                // as an optional direct target for future use.
                url: checkpoint.href,
                libraryUrl: base.href,
                fallbackUrl: checkpoint.href,
                createdAt: Date.now(),
                expires: Date.now() + 24 * 60 * 60 * 1000
            }));
        } catch (e) {}
        window.location.assign(url.href);
    } catch (error) {
        status.textContent = error.name === 'AbortError' ? 'Сервер не ответил. Повторите попытку.' : error.message;
        status.hidden = false;
    } finally {
        clearTimeout(timeout);
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = label;
    }
});
