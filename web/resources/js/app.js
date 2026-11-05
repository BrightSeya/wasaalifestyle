import './bootstrap';
import { Chart } from 'chart.js/auto';

const themeToggleButtons = document.querySelectorAll('[data-theme-toggle]');
themeToggleButtons.forEach((button) => {
    const updateThemeLabel = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        const label = isDark ? 'Switch to light mode' : 'Switch to dark mode';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
    };

    updateThemeLabel();
    button.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.add('theme-transition');
        document.documentElement.dataset.theme = nextTheme;
        const themeColor = document.querySelector('meta[name="theme-color"]');
        if (themeColor) themeColor.content = nextTheme === 'dark' ? '#090909' : '#f7f6f3';
        try {
            localStorage.setItem('wasaa-theme', nextTheme);
        } catch {
            // The current page theme still changes when storage is unavailable.
        }
        updateThemeLabel();
        window.setTimeout(() => document.documentElement.classList.remove('theme-transition'), 350);
    });
});

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');

const setSidebarOpen = (open) => {
    if (!sidebar || !sidebarBackdrop || !sidebarToggle) return;

    sidebar.classList.toggle('-translate-x-full', !open);
    sidebarBackdrop.classList.toggle('hidden', !open);
    sidebarToggle.setAttribute('aria-expanded', String(open));
    sidebarToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
};

sidebarToggle?.addEventListener('click', () => {
    setSidebarOpen(sidebarToggle.getAttribute('aria-expanded') !== 'true');
});
sidebarBackdrop?.addEventListener('click', () => setSidebarOpen(false));
sidebar?.querySelectorAll('nav a').forEach((link) => link.addEventListener('click', () => setSidebarOpen(false)));

document.querySelectorAll('[data-quote-randomize]').forEach((button) => {
    const quotes = JSON.parse(button.dataset.quotes || '[]');
    const quote = button.closest('[aria-label="A planning reminder"]')?.querySelector('[data-sidebar-quote]');

    button.addEventListener('click', () => {
        if (!quote || quotes.length < 2) return;

        const currentIndex = Number(button.dataset.currentQuote);
        const choices = quotes.map((_, index) => index).filter((index) => index !== currentIndex);
        const nextIndex = choices[Math.floor(Math.random() * choices.length)];

        quote.textContent = quotes[nextIndex];
        button.dataset.currentQuote = String(nextIndex);
    });
});

document.querySelectorAll('[data-account-menu]').forEach((menu) => {
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') menu.removeAttribute('open');
    });
});

const avatarInput = document.querySelector('[data-avatar-input]');
avatarInput?.addEventListener('change', () => {
    const file = avatarInput.files?.[0];
    if (!file) return;

    const preview = document.querySelector('[data-avatar-preview]');
    const fallback = document.querySelector('[data-avatar-fallback]');
    if (!preview) return;

    if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
    preview.src = URL.createObjectURL(file);
    preview.dataset.objectUrl = preview.src;
    preview.classList.remove('hidden');
    fallback?.classList.add('hidden');
    document.querySelector('input[name="remove_profile_photo"]')?.removeAttribute('checked');
    const removePhoto = document.querySelector('input[name="remove_profile_photo"]');
    if (removePhoto) removePhoto.checked = false;
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm || 'Are you sure?')) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-completion-chart]').forEach((chartCanvas) => {
    const days = JSON.parse(chartCanvas.dataset.chart);

    new Chart(chartCanvas, {
        type: 'bar',
        data: {
            labels: days.map((day) => day.label),
            datasets: [{
                label: 'Completion %',
                data: days.map((day) => day.percentage),
                backgroundColor: '#ffb84d',
            }],
        },
        options: {
            scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (value) => `${value}%` } } },
            plugins: { legend: { display: false } },
        },
    });
});
