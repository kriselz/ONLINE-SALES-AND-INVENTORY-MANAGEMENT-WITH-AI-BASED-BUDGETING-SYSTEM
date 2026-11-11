(function () {
    if (window.backNavigationInstalled) return;
    window.backNavigationInstalled = true;

    document.addEventListener('click', function (event) {
        const target = event.target;
        const link = target instanceof Element ? target.closest('a') : null;
        if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;

        const label = (link.textContent || '').trim();
        if (!/(?:^|[\s←])back\b/i.test(label) && !/^go\s+back\b/i.test(label)) return;
        if (!document.referrer || window.history.length < 2) return;

        const previousPage = new URL(document.referrer, window.location.href);
        if (previousPage.origin !== window.location.origin) return;

        event.preventDefault();
        window.history.back();
    });
}());
