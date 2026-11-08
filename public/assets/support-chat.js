(() => {
    const viewport = document.querySelector('[data-support-chat]');
    if (!viewport) return;
    const thread = viewport.querySelector('.support-thread');
    const older = viewport.querySelector('[data-older]');
    const notice = document.querySelector('[data-chat-notice]');
    let busy = false, exhausted = thread.children.length < 30, failures = 0, emptyPolls = 0;
    let stopped = false, pauseUntil = 0, timer, activeController;
    const bottom = () => viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 120;
    const scrollEnd = () => { viewport.scrollTop = viewport.scrollHeight; };
    scrollEnd();
    thread.addEventListener('load', () => { if (bottom()) scrollEnd(); }, true);
    async function load(past) {
        if (stopped || busy || (past && exhausted) || document.hidden || Date.now() < pauseUntil) return;
        const items = thread.querySelectorAll('[data-message-id]');
        const cursor = past ? items[0]?.dataset.messageId : items[items.length - 1]?.dataset.messageId;
        if (!cursor) return;
        busy = true;
        older.disabled = true;
        const controller = new AbortController();
        activeController = controller;
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        const keepEnd = bottom(), height = viewport.scrollHeight;
        try {
            const url = new URL(viewport.dataset.history, window.location.href);
            url.searchParams.set(past ? 'before' : 'after', cursor);
            const response = await fetch(url, {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', signal: controller.signal});
            if ([401,403,404,419].includes(response.status) || response.redirected) stopped = true;
            const retry = response.headers?.get('Retry-After');
            const retryMs = /^\d+$/.test(retry || '') ? Number(retry) * 1000 : Math.max(0, Date.parse(retry) - Date.now()) || 0;
            pauseUntil = Math.max(pauseUntil, Date.now() + retryMs);
            if (!response.ok) throw new Error('unavailable');
            const data = await response.json();
            if (past) {
                thread.insertAdjacentHTML('afterbegin', data.html);
                exhausted = !data.more;
                older.hidden = exhausted;
                viewport.scrollTop += viewport.scrollHeight - height;
            } else if (data.html) {
                emptyPolls = 0;
                thread.insertAdjacentHTML('beforeend', data.html);
                if (keepEnd) scrollEnd();
                else { notice.hidden = false; notice.textContent = 'Novas mensagens ↓'; }
            } else if (!past) {
                emptyPolls++;
            }
            failures = 0;
        } catch (_) {
            failures++;
            notice.hidden = false;
            notice.textContent = 'Atualização indisponível. Tentaremos novamente.';
        } finally { window.clearTimeout(timeout); activeController = null; busy = false; older.disabled = false; }
    }
    older.hidden = exhausted;
    older.addEventListener('click', () => load(true));
    viewport.addEventListener('scroll', () => { if (viewport.scrollTop < 60) load(true); if (bottom()) notice.hidden = true; }, {passive: true});
    notice.addEventListener('click', () => { scrollEnd(); notice.hidden = true; });
    async function poll() {
        if (stopped) return;
        if (!document.hidden && document.hasFocus()) await load(false);
        if (!stopped) timer = window.setTimeout(poll, Math.max(pauseUntil - Date.now(), failures ? Math.min(120000, 15000 * 2 ** Math.min(failures, 3)) : Math.min(60000, 15000 * (1 + emptyPolls))));
    }
    timer = window.setTimeout(poll, 15000);
    window.addEventListener('pagehide', () => { stopped = true; window.clearTimeout(timer); activeController?.abort(); });
    document.querySelectorAll('[data-file-input]').forEach(input => input.addEventListener('change', () => {
        const status = input.closest('label').querySelector('small');
        status.textContent = input.files.length ? Array.from(input.files).map(file => file.name).join(', ') : '';
        status.hidden = input.files.length === 0;
    }));
    const draft = document.querySelector('[data-support-draft]');
    const resizeDraft = () => {
        if (!draft) return;
        draft.style.height = 'auto';
        draft.style.height = Math.min(draft.scrollHeight, 120) + 'px';
    };
    draft?.addEventListener('input', resizeDraft);
    resizeDraft();
    const controls = document.querySelector('.ticket-controls');
    document.addEventListener('click', event => {
        if (controls?.open && !controls.contains(event.target)) controls.open = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && controls?.open) {
            controls.open = false;
            controls.querySelector('summary').focus();
        }
    });
})();
