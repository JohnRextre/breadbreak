document.addEventListener('DOMContentLoaded', function () {
    const sizes = ['Solo', 'Partner', 'Family'];
    const backdrop = document.querySelector('[data-modal-backdrop]');

    function setInventoryView(view) {
        const validView = view === 'grid' ? 'grid' : 'list';
        document.querySelectorAll('[data-inventory-view-content]').forEach(function (content) { content.style.display = content.dataset.inventoryViewContent === validView ? 'block' : 'none'; });
        document.querySelectorAll('[data-inventory-view]').forEach(function (button) { const active = button.dataset.inventoryView === validView; button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', active ? 'true' : 'false'); });
        window.localStorage.setItem('staff-inventory-view', validView);
    }

    document.querySelectorAll('[data-inventory-view]').forEach(function (button) { button.addEventListener('click', function () { setInventoryView(button.dataset.inventoryView); }); });
    if (document.querySelector('[data-inventory-view-content]')) setInventoryView(window.localStorage.getItem('staff-inventory-view') || 'list');
    const editPhotoInput = document.getElementById('edit-photo');
    if (editPhotoInput && editPhotoInput.form) { editPhotoInput.name = 'photo'; editPhotoInput.form.enctype = 'multipart/form-data'; }
    document.querySelectorAll('[data-photo-input]').forEach(function (input) {
        input.addEventListener('change', function () {
            const filename = input.closest('.photo-field').querySelector('[data-photo-name]');
            if (filename) filename.textContent = input.files.length ? input.files[0].name : 'No photo selected';
        });
    });
    document.querySelectorAll('.inventory-card-image img').forEach(function (image) { image.loading = 'eager'; image.fetchPriority = 'high'; });
    document.querySelectorAll('.inventory-item-card').forEach(function (card) {
        const image = card.querySelector('.inventory-card-image img');
        if (!image) return;
        card.querySelectorAll('[data-item-action]').forEach(function (button) { button.dataset.itemPhoto = image.currentSrc || image.src; });
    });

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        document.querySelectorAll('.admin-modal.is-open').forEach(function (item) { item.classList.remove('is-open'); });
        modal.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
    }

    function closeModal() {
        document.querySelectorAll('.admin-modal.is-open').forEach(function (item) { item.classList.remove('is-open'); });
        if (backdrop) backdrop.classList.remove('is-open');
    }

    document.querySelectorAll('[data-open-staff-modal]').forEach(function (button) {
        button.addEventListener('click', function () { openModal(button.dataset.openStaffModal); });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) { button.addEventListener('click', closeModal); });
    if (backdrop) backdrop.addEventListener('click', closeModal);

    function variantCard(variant) {
        const card = document.createElement('div');
        card.className = 'variant-card';
        card.innerHTML = '<div class="variant-card-heading"><strong>Service Size Variant</strong><button type="button" class="variant-remove" data-remove-variant>Remove</button></div><div class="form-field"><label>Service Size</label><select name="size[]" required>' + sizes.map(function (size) { return '<option value="' + size + '" ' + (variant && variant.service_size === size ? 'selected' : '') + '>' + size + '</option>'; }).join('') + '</select></div><div class="form-grid two"><div class="form-field"><label>SKU</label><input name="sku[]" placeholder="Enter SKU" value="' + (variant ? variant.sku : '') + '" required /></div><div class="form-field"><label>Price (Peso)</label><input name="price[]" type="number" min="0" step="0.01" value="' + (variant ? variant.price : '') + '" required /></div><div class="form-field"><label>Quantity</label><input name="quantity[]" type="number" min="0" value="' + (variant ? variant.quantity : 0) + '" required data-variant-quantity /></div><div class="form-field"><label>Availability</label><select name="availability[]"><option value="available" ' + ((!variant || variant.availability === 'available') ? 'selected' : '') + '>Available</option><option value="unavailable" ' + (variant && variant.availability === 'unavailable' ? 'selected' : '') + '>Unavailable</option></select></div></div><div class="variant-stock">Stock Status: <strong data-variant-stock></strong></div>';
        function updateStock() { const quantity = Number(card.querySelector('[data-variant-quantity]').value || 0); const output = card.querySelector('[data-variant-stock]'); output.textContent = quantity > 10 ? 'In Stock' : quantity > 0 ? 'Low Stock' : 'Out of Stock'; output.className = quantity > 10 ? 'stock-in' : quantity > 0 ? 'stock-low' : 'stock-out'; }
        card.querySelector('[data-variant-quantity]').addEventListener('input', updateStock);
        card.querySelector('[data-remove-variant]').addEventListener('click', function () { const list = card.parentElement; if (list.children.length === 1) { const error = list.parentElement.querySelector('.variant-form-error'); if (error) error.textContent = 'At least one Service Size variant is required.'; } else card.remove(); });
        updateStock();
        return card;
    }

    function addVariant(mode, variant) { const list = document.querySelector('[data-variant-list="' + mode + '"]'); if (!list) return; list.appendChild(variantCard(variant)); }
    document.querySelectorAll('[data-add-variant]').forEach(function (button) { button.addEventListener('click', function () { addVariant(button.dataset.addVariant); }); });
    const addList = document.querySelector('[data-variant-list="add"]');
    if (addList && !addList.children.length) addVariant('add', { service_size: 'Solo', sku: '', price: '', quantity: 0, availability: 'available' });
    document.querySelectorAll('[data-inventory-form]').forEach(function (form) { form.addEventListener('submit', function (event) { const values = Array.from(form.querySelectorAll('[name="size[]"]')).map(function (input) { return input.value; }); if (values.some(function (value, index) { return values.indexOf(value) !== index; })) { event.preventDefault(); const error = form.querySelector('.variant-form-error'); if (error) error.textContent = 'This Service Size has already been added.'; } }); });

    function sourceFor(action, id) { return document.querySelector('[data-item-action="' + action + '"][data-item-id="' + id + '"]'); }
    document.querySelectorAll('[data-item-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const id = button.dataset.itemId;
            const source = button.closest('.inventory-item-card') ? button : (sourceFor('view', id) || button);
            const variants = source.dataset.itemVariants ? JSON.parse(source.dataset.itemVariants) : [];
            if (button.dataset.itemAction === 'view') {
                document.querySelector('[data-detail="name"]').textContent = source.dataset.itemName;
                document.querySelector('[data-detail="category"]').textContent = source.dataset.itemCategory;
                document.querySelector('[data-detail="description"]').textContent = source.dataset.itemDescription;
                const photoBox = document.querySelector('.item-photo-placeholder');
                const photoUrl = button.dataset.itemPhoto || source.dataset.itemPhoto;
                photoBox.innerHTML = '';
                if (photoUrl) { const image = document.createElement('img'); image.src = photoUrl; image.alt = source.dataset.itemName; photoBox.appendChild(image); } else photoBox.textContent = 'No photo available';
                let total = 0;
                const prices = [];
                document.querySelector('[data-variant-details]').innerHTML = variants.map(function (variant) { const quantity = Number(variant.quantity); total += quantity; prices.push(Number(variant.price)); const status = quantity > 10 ? 'In Stock' : quantity > 0 ? 'Low Stock' : 'Out of Stock'; return '<tr><td>' + variant.service_size + '</td><td>' + variant.sku + '</td><td>₱' + Number(variant.price).toFixed(2) + '</td><td>' + quantity + '</td><td><span class="stock-badge ' + (quantity > 10 ? 'stock-in' : quantity > 0 ? 'stock-low' : 'stock-out') + '">' + status + '</span></td><td>' + (variant.availability === 'available' ? 'Available' : 'Unavailable') + '</td></tr>'; }).join('');
                document.querySelector('[data-detail="total-stock"]').textContent = total;
                document.querySelector('[data-detail="price-range"]').textContent = prices.length ? '₱' + Math.min.apply(null, prices).toFixed(2) + (prices.length > 1 ? ' – ₱' + Math.max.apply(null, prices).toFixed(2) : '') : '₱0.00';
                openModal('view-modal');
            } else if (button.dataset.itemAction === 'edit') {
                const editSource = button.closest('.inventory-item-card') ? button : sourceFor('edit', id);
                const editVariants = editSource && editSource.dataset.itemVariants ? JSON.parse(editSource.dataset.itemVariants) : [];
                document.getElementById('edit-name').value = editSource.dataset.itemName;
                document.getElementById('edit-category').value = editSource.dataset.itemCategoryId;
                document.getElementById('edit-description').value = editSource.dataset.itemDescription;
                const editPhotoName = document.querySelector('[data-photo-name="edit"]');
                if (editPhotoName) editPhotoName.textContent = editSource.dataset.itemPhoto ? 'Current photo attached' : 'No photo selected';
                document.querySelector('[data-variant-list="edit"]').innerHTML = '';
                document.querySelectorAll('#edit-item-modal .item-id').forEach(function (input) { input.value = id; });
                editVariants.forEach(function (variant) { addVariant('edit', variant); });
                openModal('edit-item-modal');
            } else {
                const deleteForm = document.querySelector('[data-delete-form]');
                deleteForm.querySelector('.item-id').value = id;
                deleteForm.dataset.itemName = source.dataset.itemName || '';
                deleteForm.dataset.variants = source.dataset.itemVariants || '[]';
                document.querySelectorAll('[data-delete-item-name]').forEach(function (element) { element.textContent = deleteForm.dataset.itemName; });
                const select = document.querySelector('[data-delete-variant]');
                select.innerHTML = JSON.parse(deleteForm.dataset.variants).map(function (variant) { return '<option value="' + variant.id + '" data-quantity="' + variant.quantity + '">' + variant.service_size + '</option>'; }).join('');
                select.dispatchEvent(new Event('change'));
                deleteForm.querySelector('[name="delete_mode"][value="quantity"]').checked = true;
                deleteForm.querySelector('[name="delete_mode"][value="item"]').checked = false;
                setDeleteMode('quantity');
                document.getElementById('delete-quantity').value = '';
                document.getElementById('delete-confirmation').value = '';
                document.querySelector('[data-delete-confirmation-value]').value = '';
                openModal('delete-modal');
            }
        });
    });

    const deleteForm = document.querySelector('[data-delete-form]');
    function updateDeleteQuantity() { const select = document.querySelector('[data-delete-variant]'); const current = Number(select.selectedOptions[0] ? select.selectedOptions[0].dataset.quantity : 0); document.querySelector('[data-current-quantity]').textContent = current; document.querySelector('[data-remaining-quantity]').textContent = Math.max(0, current - Number(document.getElementById('delete-quantity').value || 0)); }
    function setDeleteMode(mode) { const quantityMode = mode === 'quantity'; deleteForm.querySelector('[data-delete-content="quantity"]').hidden = !quantityMode; deleteForm.querySelector('[data-delete-content="item"]').hidden = quantityMode; deleteForm.querySelector('[name="action"]').value = quantityMode ? 'delete_quantity' : 'delete_item'; deleteForm.querySelector('[data-delete-quantity-button]').style.display = quantityMode ? '' : 'none'; deleteForm.querySelector('[data-delete-entire-button]').disabled = quantityMode || document.getElementById('delete-confirmation').value !== 'Delete'; }
    if (deleteForm) {
        deleteForm.querySelectorAll('[name="delete_mode"]').forEach(function (radio) { radio.addEventListener('change', function () { setDeleteMode(radio.value); }); });
        document.querySelector('[data-delete-variant]').addEventListener('change', updateDeleteQuantity);
        document.getElementById('delete-quantity').addEventListener('input', updateDeleteQuantity);
        document.getElementById('delete-confirmation').addEventListener('input', function () { deleteForm.querySelector('[data-delete-confirmation-value]').value = this.value; deleteForm.querySelector('[data-delete-entire-button]').disabled = this.value !== 'Delete'; });
        deleteForm.addEventListener('submit', function (event) { if (deleteForm.querySelector('[name="delete_mode"]:checked').value === 'quantity') { const input = document.getElementById('delete-quantity'); const current = Number(document.querySelector('[data-delete-variant]').selectedOptions[0] ? document.querySelector('[data-delete-variant]').selectedOptions[0].dataset.quantity : 0); if (!/^[1-9]\d*$/.test(input.value) || Number(input.value) > current) { event.preventDefault(); document.querySelector('[data-delete-error]').textContent = 'Enter a whole number greater than 0 and no greater than the current quantity.'; } } else if (document.getElementById('delete-confirmation').value !== 'Delete') event.preventDefault(); });
    }
});
