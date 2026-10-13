(() => {
    const root = document.querySelector('[data-payment-status]');
    if (!root) return;
    try { localStorage.removeItem('poseitech-cart-' + root.dataset.company); } catch {}
    const statuses = {approved:'Pagamento confirmado! A loja recebeu seu pedido.',test_approved:'Aprovado em teste · sem baixa financeira.',cancelled:'Pix cancelado.',rejected:'Pix recusado.',refunded:'Pagamento reembolsado.',charged_back:'Pagamento contestado.',review:'Pagamento em conferência.'};
    let stopped = Boolean(statuses[root.dataset.status]), failures = 0;
    document.getElementById('copy-pix')?.addEventListener('click', async event => {
        const input = document.getElementById('pix-code');
        try { await navigator.clipboard.writeText(input.value); event.target.textContent = 'Código copiado'; }
        catch { input.focus(); input.select(); event.target.textContent = 'Selecione e copie o código'; }
    });
    async function poll() {
        if (stopped) return;
        if (!document.hidden) {
            try {
                const response = await fetch(root.dataset.paymentStatus, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, cache:'no-store'});
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (statuses[data.status]) {
                    stopped = true;
                    document.getElementById('pix-status').textContent = statuses[data.status];
                    const instructions = document.getElementById('pix-instructions');
                    if (instructions) instructions.hidden = true;
                }
                failures = 0;
            } catch { failures++; }
        }
        if (!stopped) setTimeout(poll, Math.min(60000, 8000 * 2 ** Math.min(failures,3)));
    }
    setTimeout(poll,8000);
})();
