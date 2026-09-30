import './bootstrap';
import { Chart } from 'chart.js/auto';

document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => {
    document.getElementById('sidebar')?.classList.toggle('hidden');
});

const chartCanvas = document.getElementById('completion-chart');
if (chartCanvas) {
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
}
