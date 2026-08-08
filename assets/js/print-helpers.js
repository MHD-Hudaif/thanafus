/**
 * Print Helpers - Interactive Responsive Print Bar Controls
 * Allows toggling orientation, columns/density, zoom/scale, and printing cleanly across all printer pages.
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize orientation from URL or data attribute
    const params = new URLSearchParams(window.location.search);
    const orientation = params.get('orientation') || document.body.dataset.printOrientation || 'portrait';
    document.body.dataset.printOrientation = orientation;

    // 2. Attach handlers to any print control buttons
    document.querySelectorAll('[data-print-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.dataset.printAction;
            if (action === 'print') {
                window.print();
            } else if (action === 'toggle-orientation') {
                const current = document.body.dataset.printOrientation === 'landscape' ? 'portrait' : 'landscape';
                document.body.dataset.printOrientation = current;
                const optSelect = document.querySelector('[data-print-select="orientation"]');
                if (optSelect) optSelect.value = current;
            }
        });
    });

    // 3. Attach handlers to select dropdowns
    const orientationSelect = document.querySelector('[data-print-select="orientation"]');
    if (orientationSelect) {
        orientationSelect.value = document.body.dataset.printOrientation || 'portrait';
        orientationSelect.addEventListener('change', (e) => {
            document.body.dataset.printOrientation = e.target.value;
        });
    }

    const colsSelect = document.querySelector('[data-print-select="cols"]');
    if (colsSelect) {
        colsSelect.addEventListener('change', (e) => {
            const val = e.target.value;
            document.documentElement.style.setProperty('--print-cols', val);
        });
    }

    const scaleSelect = document.querySelector('[data-print-select="scale"]');
    if (scaleSelect) {
        scaleSelect.addEventListener('change', (e) => {
            const val = parseFloat(e.target.value) || 1;
            document.documentElement.style.setProperty('--print-scale', val);
            const printableContent = document.querySelector('.cards-grid, .id-card-sheet, .chest-number-sheet, .print-collection, .landscape-page, .judge-full-sheet, table');
            if (printableContent) {
                printableContent.style.transform = `scale(${val})`;
                printableContent.style.transformOrigin = 'top center';
            }
        });
    }
});
