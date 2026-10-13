(() => {
    const viewport = document.querySelector('[data-support-chat]');
    if (!viewport) return;
    const thread = viewport.querySelector('.support-thread');
    const older = viewport.querySelector('[data-older]');
    const notice = document.querySelector('[data-chat-notice]');
    let busy = false, exhausted = thread.children.length < 30, failures = 0, emptyPolls = 0;
    const bottom = () => viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 120;
    const scrollEnd = () => { viewport.scrollTop = viewport.scrollHeight; };
    scrollEnd();
    thread.addEventListener('load', () => { if (bottom()) scrollEnd(); }, true);
    async function load(past) {
        if (busy || (past && exhausted) || document.hidden) return;
        const items = thread.querySelectorAll('[data-message-id]');
        const cursor = past ? items[0]?.dataset.messageId : items[items.length - 1]?.dataset.messageId;
        if (!cursor) return;
        busy = true;
        older.disabled = true;
        const keepEnd = bottom(), height = viewport.scrollHeight;
        try {
            const url = new URL(viewport.dataset.history, window.location.href);
            url.searchParams.set(past ? 'before' : 'after', cursor);
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
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
        } finally { busy = false; older.disabled = false; }
    }
    older.hidden = exhausted;
    older.addEventListener('click', () => load(true));
    viewport.addEventListener('scroll', () => { if (viewport.scrollTop < 60) load(true); if (bottom()) notice.hidden = true; }, {passive: true});
    notice.addEventListener('click', () => { scrollEnd(); notice.hidden = true; });
    async function poll() {
        if (!document.hidden && document.hasFocus()) await load(false);
        window.setTimeout(poll, failures ? Math.min(120000, 15000 * 2 ** Math.min(failures, 3)) : Math.min(60000, 15000 * (1 + emptyPolls)));
    }
    window.setTimeout(poll, 15000);
    document.querySelectorAll('[data-file-input]').forEach(input => input.addEventListener('change', () => {
        const status = input.closest('label').querySelector('small');
        status.textContent = input.files.length ? Array.from(input.files).map(file => file.name).join(', ') : 'Até 5 arquivos de 5 MB cada.';
    }));
})();
