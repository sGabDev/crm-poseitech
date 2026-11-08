(() => {
    const root = document.querySelector('[data-payment-status]');
    if (!root) return;
    try { localStorage.removeItem('poseitech-cart-' + root.dataset.company); } catch {}
    const statuses = {approved:'Pagamento confirmado! A loja recebeu seu pedido.',test_approved:'Aprovado em teste · sem baixa financeira.',cancelled:'Pix cancelado.',rejected:'Pix recusado.',refunded:'Pagamento reembolsado.',charged_back:'Pagamento contestado.',review:'Pagamento em conferência.'};
    let stopped = Boolean(statuses[root.dataset.status]), failures = 0, unchanged = 0, timer, controller;
    let lastStatus = root.dataset.status;
    document.getElementById('copy-pix')?.addEventListener('click', async event => {
        const input = document.getElementById('pix-code');
        try { await navigator.clipboard.writeText(input.value); event.target.textContent = 'Código copiado'; }
        catch { input.focus(); input.select(); event.target.textContent = 'Selecione e copie o código'; }
    });
    async function poll() {
        if (stopped) return;
        let retryAfter = 0;
        if (!document.hidden) {
            controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const response = await fetch(root.dataset.paymentStatus, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, cache:'no-store', signal:controller.signal});
                if ([401,403,404,419].includes(response.status) || response.redirected) { stopped = true; return; }
                const retry = response.headers?.get('Retry-After');
                retryAfter = /^\d+$/.test(retry || '') ? Number(retry) * 1000 : Math.max(0, Date.parse(retry) - Date.now()) || 0;
                if (!response.ok) throw new Error();
                const data = await response.json();
                unchanged = data.status === lastStatus ? unchanged + 1 : 0;
                lastStatus = data.status;
                if (statuses[data.status]) {
                    stopped = true;
                    document.getElementById('pix-status').textContent = statuses[data.status];
                    const instructions = document.getElementById('pix-instructions');
                    if (instructions) instructions.hidden = true;
                }
                failures = 0;
            } catch { failures++; }
            finally { clearTimeout(timeout); controller = null; }
        }
        // Pending payments continue updating, with fewer requests when unchanged.
        if (!stopped) timer = setTimeout(poll, Math.max(retryAfter, failures ? Math.min(120000, 15000 * 2 ** Math.min(failures,3)) : Math.min(60000, 15000 * (1 + unchanged))));
    }
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); controller?.abort(); });
    timer = setTimeout(poll,15000);
})();
