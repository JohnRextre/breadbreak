<form method="POST" class="admin-form" data-inventory-form="add">
    <input type="hidden" name="action" value="create_item" />
    <div class="form-grid two">
        <div class="form-field"><label for="item-name">Item Name</label><input id="item-name" name="name" placeholder="Enter product name" value="<?php echo htmlspecialchars($formData['name'] ?? ''); ?>" required /><?php if (isset($errors['item_name'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['item_name']); ?></small><?php endif; ?></div>
        <div class="form-field"><label for="item-category">Category Menu</label><select id="item-category" name="category_id" required><option value="">Select Category Menu</option><?php foreach ($categories as $menu): ?><option value="<?php echo (int) $menu['id']; ?>" <?php echo (int) ($formData['category_id'] ?? 0) === (int) $menu['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($menu['name']); ?></option><?php endforeach; ?></select><?php if (isset($errors['item_category'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['item_category']); ?></small><?php endif; ?></div>
    </div>
    <div class="form-field"><label for="item-description">Description</label><textarea id="item-description" name="description" placeholder="Describe this bakery item..." required><?php echo htmlspecialchars($formData['description'] ?? ''); ?></textarea><?php if (isset($errors['item_description'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['item_description']); ?></small><?php endif; ?></div>
    <div class="photo-field"><label for="item-photo">Photo</label><label class="photo-drop" for="item-photo"><strong>Attach Photo</strong><small>JPG, PNG, WEBP</small><span class="photo-filename" data-photo-name="add">No photo selected</span></label><input id="item-photo" class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" data-photo-input="add" /></div>
    <h3 class="variant-section-title">Service Size &amp; Variants</h3>
    <div class="variant-list" data-variant-list="add"></div>
    <small class="field-error variant-form-error"><?php echo htmlspecialchars($errors['variants'] ?? $errors['sku'] ?? ''); ?></small>
    <button class="admin-button secondary add-variant" type="button" data-add-variant="add">+ Add Variant</button>
    <?php if (isset($errors['variants']) || isset($errors['sku'])): ?><small class="field-error"><?php echo htmlspecialchars($errors['variants'] ?? $errors['sku']); ?></small><?php endif; ?>
    <div class="modal-actions"><button class="admin-button secondary" type="button" data-close-modal>Cancel</button><button class="admin-button primary" type="submit">Add Item</button></div>
</form>
