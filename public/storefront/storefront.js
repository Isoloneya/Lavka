const video = document.querySelector('[data-campaign-video]');
const control = document.querySelector('[data-video-control]');
if (video && control) {
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    control.hidden = false;
    const update = () => {
        control.textContent = video.paused ? 'Відтворити відео' : 'Пауза';
        control.setAttribute('aria-label', control.textContent);
    };
    const play = () => video.play().catch(update);
    control.addEventListener('click', () => video.paused ? play() : video.pause());
    video.addEventListener('play', update);
    video.addEventListener('pause', update);
    video.addEventListener('error', () => { control.hidden = true; });
    preference.addEventListener('change', () => { if (preference.matches) video.pause(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) video.pause(); });
    if (!preference.matches) play();
}

let cartUpdating = false;
document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || new URL(form.action).pathname !== '/cart/change') return;
    event.preventDefault();
    if (cartUpdating) return;
    cartUpdating = true;
    const body = new FormData(form);
    if (event.submitter?.name) body.set(event.submitter.name, event.submitter.value);
    const main = document.querySelector('#main');
    main.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error('Не вдалося оновити кошик. Оновіть сторінку й перевірте результат.');
        const documentResult = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updated = documentResult.querySelector('#main');
        if (!updated) throw new Error('Оновіть сторінку, щоб перевірити кошик.');
        main.replaceChildren(...updated.childNodes);
        const notice = document.createElement('p');
        notice.className = 'sr-only';
        notice.setAttribute('role', 'status');
        notice.textContent = 'Кошик оновлено.';
        main.append(notice);
        const focusTarget = main.querySelector('.notice, h1');
        if (focusTarget) { focusTarget.tabIndex = -1; focusTarget.focus({ preventScroll: true }); }
    } catch (error) {
        const notice = document.createElement('p');
        notice.className = 'notice error';
        notice.setAttribute('role', 'alert');
        notice.textContent = error instanceof Error ? error.message : 'Не вдалося оновити кошик.';
        main.prepend(notice);
    } finally {
        main.removeAttribute('aria-busy');
        cartUpdating = false;
    }
});
