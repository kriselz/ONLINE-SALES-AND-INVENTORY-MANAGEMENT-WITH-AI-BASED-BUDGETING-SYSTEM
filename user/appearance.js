(function () {
    const allowedThemes = ['light', 'dark', 'system'];
    const allowedAccents = ['#8d2638', '#c56b7b', '#7f3f98', '#2856a6', '#4d963b', '#df7b24', '#718096'];
    const root = document.documentElement;
    const originalFontStyles = new WeakMap();
    let activeScale = 1;
    let fontScaleInitialized = false;

    function isAppearanceTextElement(element) {
        const sidebar = element.closest('.sidebar');
        const ownerSidebarControl = sidebar && sidebar.querySelector('.owner-sidebar-toggle');
        return !element.matches('script, style, noscript, svg, svg *, canvas, iframe, video, audio, .material-icons, .material-icons-outlined, .bi')
            && !(ownerSidebarControl && element.matches('.sidebar a, .sidebar .nav-menu-toggle'));
    }

    function recordBaseFontSize(element) {
        if (!isAppearanceTextElement(element) || originalFontStyles.has(element)) return;
        const currentSize = parseFloat(window.getComputedStyle(element).fontSize);
        if (Number.isFinite(currentSize) && currentSize > 0) {
            const baseSize = document.body.classList.contains('appearance-font-scaled')
                ? currentSize / activeScale
                : currentSize;
            originalFontStyles.set(element, {
                size: baseSize,
                inlineSize: element.style.getPropertyValue('font-size'),
                inlinePriority: element.style.getPropertyPriority('font-size')
            });
        }
    }

    function applyElementFontScale(element) {
        const original = originalFontStyles.get(element);
        if (!original) return;
        if (activeScale === 1) {
            if (original.inlineSize) {
                element.style.setProperty('font-size', original.inlineSize, original.inlinePriority);
            } else {
                element.style.removeProperty('font-size');
            }
            return;
        }
        element.style.setProperty(
            'font-size',
            'calc(' + original.size + 'px * var(--appearance-font-scale))',
            'important'
        );
    }

    function initializeFontScale(fontScale) {
        const elements = Array.from(document.querySelectorAll('body *'));
        elements.forEach(recordBaseFontSize);
        fontScaleInitialized = true;
        applyFontScale(fontScale);
    }

    function applyTheme(theme) {
        const selectedTheme = allowedThemes.includes(theme) ? theme : 'light';
        const useDark = selectedTheme === 'dark'
            || (selectedTheme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.body.classList.toggle('dark-mode', useDark);
        return selectedTheme;
    }

    function applyAccent(accent) {
        const selectedAccent = allowedAccents.includes(String(accent).toLowerCase())
            ? String(accent).toLowerCase()
            : allowedAccents[0];
        const red = parseInt(selectedAccent.slice(1, 3), 16);
        const green = parseInt(selectedAccent.slice(3, 5), 16);
        const blue = parseInt(selectedAccent.slice(5, 7), 16);
        root.style.setProperty('--appearance-accent', selectedAccent);
        root.style.setProperty('--appearance-accent-rgb', red + ', ' + green + ', ' + blue);
        return selectedAccent;
    }

    function applyFontScale(fontScale) {
        const requestedScale = Number(fontScale);
        const scale = Number.isFinite(requestedScale) ? Math.min(1.15, Math.max(0.9, requestedScale)) : 1;
        if (!fontScaleInitialized && document.readyState === 'loading') {
            root.style.setProperty('--appearance-font-scale', String(scale));
            return String(scale);
        }

        const elements = Array.from(document.querySelectorAll('body *'));
        elements.forEach(recordBaseFontSize);
        activeScale = scale;
        root.style.setProperty('--appearance-font-scale', String(scale));
        document.body.classList.toggle('appearance-font-scaled', scale !== 1);
        elements.forEach(applyElementFontScale);
        return String(scale);
    }

    window.applyGlobalAppearance = function (theme, accent, fontScale) {
        return {
            theme: applyTheme(theme),
            accent: applyAccent(accent),
            fontScale: applyFontScale(fontScale)
        };
    };

    const savedTheme = localStorage.getItem('appearanceTheme') || 'light';
    const savedAccent = localStorage.getItem('appearanceAccent') || allowedAccents[0];
    const savedFontScale = localStorage.getItem('appearanceFontScale') || '1';
    applyTheme(savedTheme);
    applyAccent(savedAccent);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initializeFontScale(root.style.getPropertyValue('--appearance-font-scale') || savedFontScale);
        }, { once: true });
    } else {
        initializeFontScale(savedFontScale);
    }

    const systemThemePreference = window.matchMedia('(prefers-color-scheme: dark)');
    systemThemePreference.addEventListener('change', function () {
        if ((localStorage.getItem('appearanceTheme') || 'light') === 'system') {
            applyTheme('system');
        }
    });

    window.addEventListener('storage', function (event) {
        if (['appearanceTheme', 'appearanceAccent', 'appearanceFontScale'].includes(event.key)) {
            window.applyGlobalAppearance(
                localStorage.getItem('appearanceTheme') || 'light',
                localStorage.getItem('appearanceAccent') || allowedAccents[0],
                localStorage.getItem('appearanceFontScale') || '1'
            );
        }
    });

    const appearanceObserver = new MutationObserver(function (records) {
        records.forEach(function (record) {
            record.addedNodes.forEach(function (node) {
                if (node.nodeType !== Node.ELEMENT_NODE) return;
                recordBaseFontSize(node);
                applyElementFontScale(node);
                node.querySelectorAll('*').forEach(function (element) {
                    recordBaseFontSize(element);
                    applyElementFontScale(element);
                });
            });
        });
    });
    appearanceObserver.observe(document.body, { childList: true, subtree: true });
}());
