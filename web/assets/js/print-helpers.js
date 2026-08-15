/**
 * Print Helpers - Interactive Responsive Print Bar Controls
 * Allows toggling orientation, columns/density, zoom/scale, blind scoring, judges count, and printing cleanly across all score sheets.
 */
document.addEventListener('DOMContentLoaded', () => {
    const params = new URLSearchParams(window.location.search);

    // 1. Initialize orientation from URL or data attribute
    const orientation = params.get('orientation') || document.body.dataset.printOrientation || 'landscape';
    document.body.dataset.printOrientation = orientation;

    // Inject dynamic print style element if not present
    let dynamicPrintStyle = document.getElementById('dynamic-print-style');
    if (!dynamicPrintStyle) {
        dynamicPrintStyle = document.createElement('style');
        dynamicPrintStyle.id = 'dynamic-print-style';
        document.head.appendChild(dynamicPrintStyle);
    }

    function updatePageOrientationStyle(orient) {
        document.body.dataset.printOrientation = orient;
        const sizeVal = orient === 'portrait' ? 'A4 portrait' : 'A4 landscape';
        dynamicPrintStyle.textContent = `@media print { @page { size: ${sizeVal}; margin: 6mm 8mm; } }`;
    }

    updatePageOrientationStyle(orientation);

    // 2. Attach handlers to print action buttons
    document.querySelectorAll('[data-print-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.dataset.printAction;
            if (action === 'print') {
                window.print();
            } else if (action === 'toggle-orientation') {
                const current = document.body.dataset.printOrientation === 'landscape' ? 'portrait' : 'landscape';
                updatePageOrientationStyle(current);
                const optSelect = document.querySelector('[data-print-select="orientation"]');
                if (optSelect) optSelect.value = current;
            }
        });
    });

    // 3. Orientation Select Dropdown
    const orientationSelect = document.querySelector('[data-print-select="orientation"]');
    if (orientationSelect) {
        orientationSelect.value = document.body.dataset.printOrientation || 'landscape';
        orientationSelect.addEventListener('change', (e) => {
            updatePageOrientationStyle(e.target.value);
        });
    }

    // 4. Scale Select Dropdown
    const scaleSelect = document.querySelector('[data-print-select="scale"]');
    if (scaleSelect) {
        scaleSelect.addEventListener('change', (e) => {
            const val = parseFloat(e.target.value) || 1;
            document.documentElement.style.setProperty('--print-scale', val);
            const targets = document.querySelectorAll('.cards-grid, .id-card-sheet, .chest-number-sheet, .print-collection, .landscape-page, .judge-full-sheet, .judge-landscape-sheet, .sheet-card');
            targets.forEach(el => {
                el.style.transform = val === 1 ? 'none' : `scale(${val})`;
                el.style.transformOrigin = 'top center';
            });
        });
    }

    // 5. Blind Scoring Toggle (Show / Hide Participant Names)
    const toggleNames = document.querySelector('[data-print-toggle="names"]');
    if (toggleNames) {
        const applyNamesToggle = () => {
            if (toggleNames.checked) {
                document.body.classList.remove('hide-participant-names');
            } else {
                document.body.classList.add('hide-participant-names');
            }
        };
        applyNamesToggle();
        toggleNames.addEventListener('change', applyNamesToggle);
    }

    // 6. Total Column Toggle
    const toggleTotal = document.querySelector('[data-print-toggle="total"]');
    if (toggleTotal) {
        const applyTotalToggle = () => {
            if (toggleTotal.checked) {
                document.body.classList.remove('hide-total-column');
            } else {
                document.body.classList.add('hide-total-column');
            }
        };
        applyTotalToggle();
        toggleTotal.addEventListener('change', applyTotalToggle);
    }

    // 7. Rank Column Toggle
    const toggleRank = document.querySelector('[data-print-toggle="rank"]');
    if (toggleRank) {
        const applyRankToggle = () => {
            if (toggleRank.checked) {
                document.body.classList.add('show-rank-column');
            } else {
                document.body.classList.remove('show-rank-column');
            }
        };
        applyRankToggle();
        toggleRank.addEventListener('change', applyRankToggle);
    }

    // 8. Notes Column Toggle
    const toggleNotes = document.querySelector('[data-print-toggle="notes"]');
    if (toggleNotes) {
        const applyNotesToggle = () => {
            if (toggleNotes.checked) {
                document.body.classList.remove('hide-notes-column');
            } else {
                document.body.classList.add('hide-notes-column');
            }
        };
        applyNotesToggle();
        toggleNotes.addEventListener('change', applyNotesToggle);
    }

    // 9. Signature Block Toggle
    const toggleFooter = document.querySelector('[data-print-toggle="footer"]');
    if (toggleFooter) {
        const applyFooterToggle = () => {
            if (toggleFooter.checked) {
                document.body.classList.remove('hide-sheet-footer');
            } else {
                document.body.classList.add('hide-sheet-footer');
            }
        };
        applyFooterToggle();
        toggleFooter.addEventListener('change', applyFooterToggle);
    }

    // 10. Judges Count Filter
    const judgesSelect = document.querySelector('[data-print-select="judges"]');
    if (judgesSelect) {
        const applyJudgesFilter = () => {
            const count = parseInt(judgesSelect.value, 10) || 2;
            document.querySelectorAll('[data-judge-number]').forEach(sheet => {
                const jNum = parseInt(sheet.getAttribute('data-judge-number'), 10);
                if (jNum <= count) {
                    sheet.style.display = '';
                    sheet.classList.remove('no-print-hide');
                } else {
                    sheet.style.display = 'none';
                    sheet.classList.add('no-print-hide');
                }
            });
        };
        applyJudgesFilter();
        judgesSelect.addEventListener('change', applyJudgesFilter);
    }

    // 11. Extra Blank Rows Filter
    const blankRowsSelect = document.querySelector('[data-print-select="blank-rows"]');
    if (blankRowsSelect) {
        const applyBlankRows = () => {
            const count = parseInt(blankRowsSelect.value, 10) || 0;
            document.querySelectorAll('.extra-blank-row').forEach(row => {
                const index = parseInt(row.getAttribute('data-blank-index'), 10);
                if (index <= count) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        };
        applyBlankRows();
        blankRowsSelect.addEventListener('change', applyBlankRows);
    }
});

// Copy Tables to Clipboard in native Excel-compatible TSV format
window.exportAllTablesToExcel = function(tableSelector) {
    const tables = document.querySelectorAll(tableSelector);
    if (tables.length === 0) {
        alert('No data tables found on this page.');
        return;
    }
    
    let csv = [];
    
    tables.forEach((table, tableIdx) => {
        let titleText = "";
        
        // Find title headers relative to each printable sheet card
        const parentPage = table.closest('.landscape-page, .portrait-page, .judge-full-sheet, .judge-landscape-sheet, .judge-portrait-sheet, .sheet-card, .emcee-sheet, .emcee-page-card, .sheet-container, .print-container');
        if (parentPage) {
            const hTitle = parentPage.querySelector('.program-title, .print-header h2, .sheet-title, h1, h2, h3');
            if (hTitle) {
                titleText = hTitle.innerText.trim();
            }
        }
        
        if (titleText) {
            csv.push('"' + titleText.replace(/"/g, '""') + '"');
        }
        
        const rows = table.querySelectorAll('tr');
        for (let i = 0; i < rows.length; i++) {
            // Skip hidden rows/headers
            if (rows[i].style.display === 'none' || rows[i].classList.contains('no-print-hide')) continue;
            
            let row = [];
            const cols = rows[i].querySelectorAll('td, th');
            for (let j = 0; j < cols.length; j++) {
                let text = cols[j].innerText.trim();
                text = text.replace(/"/g, '""');
                row.push('"' + text + '"');
            }
            csv.push(row.join('\t'));
        }
        
        // Add double spacing between distinct tables
        csv.push('');
        csv.push('');
    });
    
    const csvString = csv.join('\r\n');
    
    navigator.clipboard.writeText(csvString).then(() => {
        alert('Table data successfully copied to clipboard in Excel format!\nOpen Microsoft Excel and press Ctrl+V (Paste) to fill the cells.');
    }).catch(err => {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = csvString;
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            alert('Table data successfully copied to clipboard in Excel format!\nOpen Microsoft Excel and press Ctrl+V (Paste) to fill the cells.');
        } catch (e) {
            alert('Failed to copy table data: ' + err);
        }
        document.body.removeChild(textarea);
    });
};


