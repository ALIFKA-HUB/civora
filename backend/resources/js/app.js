const themeKey = 'civora-theme';
const themeSelect = document.querySelector('[data-theme-select]');

function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    if (themeSelect) themeSelect.value = theme;
}

applyTheme(localStorage.getItem(themeKey) || 'system');
themeSelect?.addEventListener('change', (event) => {
    localStorage.setItem(themeKey, event.target.value);
    applyTheme(event.target.value);
});
