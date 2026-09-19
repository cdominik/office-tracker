/* Sets the effective colour theme on <html> before the page paints, so there is no flash.
   Priority: explicit cookie (op_theme=light|dark) overrides the OS preference. */
(function () {
    try {
        var m = document.cookie.match(/(?:^|; )op_theme=(light|dark)/);
        var choice = m ? m[1] : 'auto';
        var dark = choice === 'dark' ||
            (choice !== 'light' && window.matchMedia &&
             window.matchMedia('(prefers-color-scheme: dark)').matches);
        if (dark) document.documentElement.classList.add('theme-dark');
    } catch (e) {}
})();
