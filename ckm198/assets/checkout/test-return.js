(() => {
    const c = window.CKM_TEST_RETURN;
    const status = document.getElementById('test-pay-status');
    const retry = document.getElementById('test-pay-retry');
    let busy = false, timer, attempts = 0;
    async function check() {
        if (busy) return;
        clearTimeout(timer); busy = true; retry.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(c.ajax_url, {method:'POST', credentials:'same-origin', signal:controller.signal,
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:new URLSearchParams({action:'ckmqp_test_status', order_id:c.order_id, nonce:c.nonce})});
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.data && data.data.message || 'Не удалось проверить оплату.');
            if (data.data.status === 'succeeded') {
                status.textContent = 'Тестовая оплата подтверждена. Покупка сохранена.';
                window.location.assign(c.cabinet_url); return;
            }
            if (data.data.status === 'canceled') { status.textContent = 'Платёж отменён. Можно вернуться в кабинет и попробовать снова.'; return; }
            status.textContent = 'Подтверждение ещё не получено. Ожидаем оплату…';
            if (++attempts < 24) timer = setTimeout(check, 5000);
            else status.textContent = 'Подтверждение пока не получено. Нажмите «Проверить ещё раз» позже.';
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'Сервер не ответил. Повторите проверку.' : error.message;
        } finally { clearTimeout(timeout); busy = false; retry.disabled = false; }
    }
    retry.addEventListener('click', () => { attempts = 0; check(); });
    check();
})();
