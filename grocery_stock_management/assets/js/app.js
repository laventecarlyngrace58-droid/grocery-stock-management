document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');

    if (sidebar && typeof bootstrap !== 'undefined') {
        sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.matchMedia('(min-width: 1200px)').matches) {
                    return;
                }

                const drawer = bootstrap.Offcanvas.getInstance(sidebar);
                if (drawer) {
                    drawer.hide();
                }
            });
        });
    }

    const printReportButton = document.getElementById('printReportButton');
    if (printReportButton) {
        printReportButton.addEventListener('click', () => window.print());
    }

    const stockForm = document.querySelector('[data-stock-form]');
    if (!stockForm) {
        return;
    }

    const productSelect = stockForm.querySelector('#stockProduct');
    if (!productSelect) {
        return;
    }

    const productName = document.getElementById('stockProductName');
    const productSku = document.getElementById('stockProductSku');
    const currentQuantity = document.getElementById('stockCurrentQuantity');
    const productUnit = document.getElementById('stockProductUnit');

    const updateProductSummary = () => {
        const selectedOption = productSelect.options[productSelect.selectedIndex];

        if (!selectedOption || !selectedOption.dataset.productName) {
            productName.textContent = 'Choose a product';
            productSku.textContent = 'Product SKU';
            currentQuantity.textContent = '—';
            productUnit.textContent = '';
            return;
        }

        productName.textContent = selectedOption.dataset.productName;
        productSku.textContent = `SKU: ${selectedOption.dataset.sku}`;
        currentQuantity.textContent = selectedOption.dataset.quantity;
        productUnit.textContent = selectedOption.dataset.unit;
    };

    productSelect.addEventListener('change', updateProductSummary);
    updateProductSummary();

    let submitting = false;
    stockForm.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        const submitButton = stockForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }
    });
});
