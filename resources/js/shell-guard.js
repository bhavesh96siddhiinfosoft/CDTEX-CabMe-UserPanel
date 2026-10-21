/**
 * Last-resort layout guard for the map-first shell.
 *
 * The frame is specified three ways already (inlined critical CSS, the bundled
 * stylesheet, and longhand fallbacks). This measures the result and repairs it
 * if the shell still did not fill the viewport — a failure that showed up as a
 * sliver of map with a blank screen beneath it, and which could not be
 * reproduced in headless Chrome. Whatever the cause, the geometry is checked
 * here rather than assumed.
 *
 * It also records what it measured on `window.__cabmeShell`, so a screenshot of
 * the console says exactly which rule failed instead of leaving us guessing.
 */
function measure() {
    const shell = document.querySelector('.shell');
    if (!shell) return null;

    const header = document.querySelector('body > header');
    const headerHeight = header ? Math.round(header.getBoundingClientRect().height) : 0;
    const rect = shell.getBoundingClientRect();

    return {
        viewport: window.innerHeight,
        headerHeight,
        shellHeight: Math.round(rect.height),
        shellBottom: Math.round(rect.bottom),
        expected: window.innerHeight - headerHeight,
        bodyPosition: getComputedStyle(document.body).position,
        bodyDisplay: getComputedStyle(document.body).display,
        build: document.body.dataset.build || 'unknown',
    };
}

function enforce() {
    const m = measure();
    if (!m) return;

    // Allow a couple of pixels of rounding before deciding it is wrong.
    const broken = m.shellHeight < m.expected - 4;

    window.__cabmeShell = { ...m, repaired: broken };

    if (!broken) return;

    const shell = document.querySelector('.shell');
    if (!shell) return;

    // Pin the frame directly, with !important — a plain inline style still
    // loses to an !important rule, and this has to win whatever the cause.
    const pin = (el, rules) => {
        for (const [prop, value] of Object.entries(rules)) {
            el.style.setProperty(prop, value, 'important');
        }
    };

    pin(document.documentElement, { height: '100%', overflow: 'hidden' });

    pin(document.body, {
        position: 'fixed',
        top: '0', right: '0', bottom: '0', left: '0',
        width: '100%', height: '100%',
        margin: '0',
        display: 'flex',
        'flex-direction': 'column',
        overflow: 'hidden',
    });

    const header = document.querySelector('body > header');
    if (header) pin(header, { flex: 'none' });

    pin(shell, {
        flex: '1 1 auto',
        'min-height': '0',
        height: `${m.expected}px`,
        position: 'relative',
        overflow: 'hidden',
    });

    // Leaflet caches the container size, so tell it the map just grew.
    window.dispatchEvent(new Event('resize'));

    console.warn('[cabme] shell layout was repaired at runtime', window.__cabmeShell);
}

/**
 * Focus-scroll containment. `overflow: hidden` boxes are still scrollable
 * programmatically, and focusing a visually-hidden radio makes the browser
 * scroll EVERY ancestor as needed — including `.shell` itself, which shifted
 * the map and sheet up and left a blank band below. The labels are positioned
 * now so the hidden inputs sit on their tiles, but this backstop makes the
 * failure structurally impossible: any scroll offset that lands on a
 * non-scrolling frame element is removed the moment it appears.
 */
function pinScrollables() {
    const reset = (el) => {
        if (el && (el.scrollTop !== 0 || el.scrollLeft !== 0)) {
            el.scrollTop = 0;
            el.scrollLeft = 0;
        }
    };

    const guard = () => {
        reset(document.documentElement);
        reset(document.body);
        reset(document.querySelector('.shell'));
        if (window.scrollY || window.scrollX) window.scrollTo(0, 0);
    };

    // Capture phase sees scrolls on any element, not just the document.
    document.addEventListener('scroll', (event) => {
        const target = event.target;
        const isFrame = target === document
            || target === document.documentElement
            || target === document.body
            || (target instanceof Element && target.classList.contains('shell'));

        if (isFrame) guard();
    }, { capture: true, passive: true });

    guard();
}

if (document.querySelector('.shell') || document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        enforce();
        pinScrollables();
    });
    window.addEventListener('load', enforce);
    window.addEventListener('resize', enforce);
}
