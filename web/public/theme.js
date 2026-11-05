(() => {
    try {
        const savedTheme = localStorage.getItem('wasaa-theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = savedTheme === 'light' || savedTheme === 'dark'
            ? savedTheme
            : (systemPrefersDark ? 'dark' : 'light');

        document.documentElement.dataset.theme = theme;
        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) themeColor.content = theme === 'dark' ? '#090909' : '#f7f6f3';
    } catch {
        document.documentElement.dataset.theme = 'light';
    }
})();
