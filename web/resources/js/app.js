import './bootstrap';

document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => {
    document.getElementById('sidebar')?.classList.toggle('hidden');
});
