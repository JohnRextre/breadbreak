document.addEventListener('DOMContentLoaded', function () {
    const body = document.body;
    const sidebar = document.getElementById('admin-sidebar');
    const menuToggle = document.querySelector('.menu-toggle');
    const sidebarClose = document.querySelector('.sidebar-close');
    const overlay = document.querySelector('.sidebar-overlay');
    const sidebarCollapse = document.querySelector('.sidebar-collapse');
    const sidebarPreferenceKey = 'breadbreak_admin_sidebar';

    const setSidebarCollapsed = function (collapsed) {
        body.classList.toggle('sidebar-collapsed', collapsed);
        if (sidebarCollapse) {
            sidebarCollapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            const icon = sidebarCollapse.querySelector('.collapse-icon');
            if (icon) {
                if (collapsed) {
                    icon.classList.remove('fa-angles-left');
                    icon.classList.add('fa-angles-right');
                } else {
                    icon.classList.remove('fa-angles-right');
                    icon.classList.add('fa-angles-left');
                }
            }
        }
        try {
            window.localStorage.setItem(sidebarPreferenceKey, collapsed ? 'collapsed' : 'expanded');
        } catch (e) {}
    };

    if (window.localStorage.getItem(sidebarPreferenceKey) === 'collapsed') {
        setSidebarCollapsed(true);
    }
    
    if (sidebarCollapse) {
        sidebarCollapse.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setSidebarCollapsed(!body.classList.contains('sidebar-collapsed'));
        });
    }

    const setSidebar = function (open) {
        body.classList.toggle('sidebar-open', open);
        if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    };

    if (menuToggle) {
        menuToggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setSidebar(!body.classList.contains('sidebar-open'));
        });
    }
    if (sidebarClose) {
        sidebarClose.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setSidebar(false);
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setSidebar(false);
        });
    }
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setSidebar(false);
            });
        });
    }

    const backdrop = document.querySelector('[data-modal-backdrop]');
    const modals = document.querySelectorAll('.admin-modal');
    const closeModals = function () {
        modals.forEach(function (modal) { modal.classList.remove('is-open'); });
        if (backdrop) backdrop.classList.remove('is-open');
        document.querySelectorAll('.action-menu.open').forEach(function (menu) { menu.classList.remove('open'); });
    };
    const openModal = function (modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        closeModals();
        modal.classList.add('is-open');
        if (backdrop) backdrop.classList.add('is-open');
    };

    document.querySelectorAll('[data-open-modal]').forEach(function (button) {
        button.addEventListener('click', function () { openModal(button.dataset.openModal); });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', closeModals);
    });
    if (backdrop) backdrop.addEventListener('click', closeModals);

    let activeMenu = null;
    let activeMenuButton = null;
    const closeActionMenu = function () {
        if (!activeMenu) return;
        activeMenu.classList.remove('open', 'floating-menu');
        if (activeMenuButton) activeMenuButton.parentElement.appendChild(activeMenu);
        if (activeMenuButton) activeMenuButton.setAttribute('aria-expanded', 'false');
        activeMenu = null;
        activeMenuButton = null;
    };
    const positionActionMenu = function (menu, button) {
        const buttonRect = button.getBoundingClientRect();
        const menuWidth = 210;
        const menuHeight = menu.offsetHeight;
        const left = Math.min(Math.max(8, buttonRect.right - menuWidth), window.innerWidth - menuWidth - 8);
        const top = buttonRect.bottom + menuHeight + 8 > window.innerHeight
            ? Math.max(8, buttonRect.top - menuHeight - 8)
            : buttonRect.bottom + 8;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    };

    document.querySelectorAll('.dots-button').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            const menu = button.nextElementSibling;
            if (activeMenu === menu) { closeActionMenu(); return; }
            closeActionMenu();
            document.body.appendChild(menu);
            menu.classList.add('open', 'floating-menu');
            activeMenu = menu;
            activeMenuButton = button;
            button.setAttribute('aria-expanded', menu.classList.contains('open') ? 'true' : 'false');
            positionActionMenu(menu, button);
        });
    });
    document.addEventListener('click', function () {
        closeActionMenu();
    });

    document.querySelectorAll('[data-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeActionMenu();
            const modalId = button.dataset.action === 'password' ? 'password-modal' : button.dataset.action === 'status' ? 'status-modal' : 'delete-modal';
            document.querySelectorAll('#' + modalId + ' .modal-user-id').forEach(function (input) { input.value = button.dataset.userId; });
            if (modalId === 'status-modal') {
                const status = document.getElementById('account-status');
                status.value = button.dataset.userStatus === 'inactive' ? 'inactive' : 'active';
            }
            openModal(modalId);
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;
            const isCurrentlyPassword = input.type === 'password';
            input.type = isCurrentlyPassword ? 'text' : 'password';
            const icon = button.querySelector('i');
            if (icon) {
                if (isCurrentlyPassword) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
            button.setAttribute('aria-label', isCurrentlyPassword ? 'Hide password' : 'Show password');
        });
    });

    const statusSelect = document.getElementById('account-status');
    const reasonField = document.getElementById('status-reason');
    if (statusSelect && reasonField) {
        const updateReasonState = function () {
            reasonField.required = statusSelect.value === 'inactive';
        };
        statusSelect.addEventListener('change', updateReasonState);
        updateReasonState();
    }

    const deleteConfirmation = document.getElementById('delete-confirmation');
    const deleteButton = document.querySelector('#delete-modal .danger-button');
    if (deleteConfirmation && deleteButton) {
        deleteConfirmation.addEventListener('input', function () {
            deleteButton.disabled = deleteConfirmation.value === 'Delete' ? false : true;
        });
    }

    const adminPhotoInput = document.getElementById('admin-profile-photo');
    const adminPhotoTrigger = document.querySelector('[data-admin-photo-trigger]');
    const adminProfileSave = document.querySelector('.admin-profile-save');
    const staffProfileForm = document.getElementById('admin-profile-photo-form');
    const staffProfileFields = [
        ['staff-first-name', 'first_name'],
        ['staff-last-name', 'last_name'],
        ['staff-phone', 'phone'],
        ['staff-email', 'email'],
        ['admin-first-name', 'first_name'],
        ['admin-last-name', 'last_name'],
        ['admin-phone', 'phone'],
        ['admin-email', 'email']
    ];
    if (staffProfileForm) {
        const profileAction = staffProfileForm.querySelector('input[name="action"]');
        if (profileAction) profileAction.value = 'update_profile';
        staffProfileFields.forEach(function (field) {
            const input = document.getElementById(field[0]);
            if (!input) return;
            input.removeAttribute('readonly');
            input.name = field[1];
            input.setAttribute('form', 'admin-profile-photo-form');
            input.addEventListener('input', function () { if (adminProfileSave) adminProfileSave.disabled = false; });
        });
        if (adminProfileSave) adminProfileSave.disabled = true;
    }
    const adminCropModal = document.querySelector('[data-admin-crop-modal]');
    const adminCropBackdrop = document.querySelector('[data-admin-crop-backdrop]');
    const adminCropStage = document.querySelector('[data-admin-crop-stage]');
    const adminCropImage = document.querySelector('[data-admin-crop-image]');
    const adminZoomInput = document.querySelector('[data-admin-zoom]');
    let adminCropScale = 1;
    let adminCropX = 0;
    let adminCropY = 0;
    let adminDragStartX = 0;
    let adminDragStartY = 0;
    let adminDragging = false;
    const renderAdminCropImage = function () {
        adminCropImage.style.transform = 'translate(-50%, -50%) translate(' + adminCropX + 'px, ' + adminCropY + 'px) scale(' + adminCropScale + ')';
    };
    const closeAdminCrop = function (clearInput) {
        if (adminCropModal) adminCropModal.classList.remove('is-open');
        if (adminCropBackdrop) adminCropBackdrop.classList.remove('is-open');
        if (clearInput && adminPhotoInput) adminPhotoInput.value = '';
    };
    const openAdminCrop = function (file) {
        const reader = new FileReader();
        reader.onload = function (event) {
            adminCropImage.src = event.target.result;
            adminCropImage.onload = function () {
                adminCropScale = 260 / Math.min(adminCropImage.naturalWidth, adminCropImage.naturalHeight);
                adminZoomInput.value = 1;
                adminCropX = 0;
                adminCropY = 0;
                renderAdminCropImage();
                adminCropModal.classList.add('is-open');
                adminCropBackdrop.classList.add('is-open');
            };
        };
        reader.readAsDataURL(file);
    };
    if (adminPhotoInput && adminPhotoTrigger) {
        adminPhotoTrigger.addEventListener('click', function () {
            adminPhotoInput.click();
        });
        adminPhotoInput.addEventListener('change', function () {
            const file = adminPhotoInput.files && adminPhotoInput.files[0];
            if (file && file.type.startsWith('image/')) openAdminCrop(file);
        });
    }
    if (adminZoomInput) adminZoomInput.addEventListener('input', function () { adminCropScale = (260 / Math.min(adminCropImage.naturalWidth, adminCropImage.naturalHeight)) * Number(adminZoomInput.value); renderAdminCropImage(); });
    if (adminCropStage) {
        adminCropStage.addEventListener('pointerdown', function (event) { adminDragging = true; adminCropStage.setPointerCapture(event.pointerId); adminDragStartX = event.clientX - adminCropX; adminDragStartY = event.clientY - adminCropY; });
        adminCropStage.addEventListener('pointermove', function (event) { if (!adminDragging) return; adminCropX = event.clientX - adminDragStartX; adminCropY = event.clientY - adminDragStartY; renderAdminCropImage(); });
        adminCropStage.addEventListener('pointerup', function () { adminDragging = false; });
    }
    document.querySelectorAll('[data-admin-close-crop]').forEach(function (button) { button.addEventListener('click', function () { closeAdminCrop(true); }); });
    if (adminCropBackdrop) adminCropBackdrop.addEventListener('click', function () { closeAdminCrop(true); });
    const adminCropSave = document.querySelector('[data-admin-save-crop]');
    if (adminCropSave) adminCropSave.addEventListener('click', function () {
        const canvas = document.createElement('canvas');
        canvas.width = 500;
        canvas.height = 500;
        const context = canvas.getContext('2d');
        const ratio = 500 / 260;
        context.fillStyle = '#f5f0eb';
        context.fillRect(0, 0, 500, 500);
        context.translate(250 + adminCropX * ratio, 250 + adminCropY * ratio);
        context.scale(adminCropScale * ratio, adminCropScale * ratio);
        context.drawImage(adminCropImage, -adminCropImage.naturalWidth / 2, -adminCropImage.naturalHeight / 2);
        canvas.toBlob(function (blob) {
            const croppedFile = new File([blob], 'profile-photo.jpg', { type: 'image/jpeg' });
            const transfer = new DataTransfer();
            transfer.items.add(croppedFile);
            adminPhotoInput.files = transfer.files;
            document.querySelector('.admin-account-photo-preview').innerHTML = '<img src="' + URL.createObjectURL(blob) + '" alt="Profile photo preview">';
            if (adminProfileSave) adminProfileSave.disabled = false;
            closeAdminCrop(false);
        }, 'image/jpeg', .9);
    });

    const staffNewPassword = document.getElementById('staff-new-password');
    const staffStrengthLabel = document.querySelector('[data-staff-strength-label]');
    const staffStrengthBar = document.querySelector('[data-staff-strength-bar]');
    const staffStrengthHelp = document.querySelector('[data-staff-strength-help]');
    if (staffNewPassword && staffStrengthLabel && staffStrengthBar) {
        const staffConfirmPassword = document.getElementById('staff-confirm-password');
        const staffMatchMessage = document.createElement('small');
        staffMatchMessage.className = 'staff-password-match';
        staffConfirmPassword.parentElement.appendChild(staffMatchMessage);
        const updateStaffPasswordMatch = function () {
            if (!staffConfirmPassword.value) {
                staffMatchMessage.textContent = '';
                staffMatchMessage.className = 'staff-password-match';
            } else if (staffNewPassword.value === staffConfirmPassword.value) {
                staffMatchMessage.textContent = 'Passwords match.';
                staffMatchMessage.className = 'staff-password-match matches';
            } else {
                staffMatchMessage.textContent = 'Passwords do not match.';
                staffMatchMessage.className = 'staff-password-match does-not-match';
            }
        };
        staffNewPassword.addEventListener('input', updateStaffPasswordMatch);
        staffConfirmPassword.addEventListener('input', updateStaffPasswordMatch);
        staffNewPassword.addEventListener('input', function () {
            const value = staffNewPassword.value;
            let score = 0;
            if (value.length >= 8) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;
            const level = value === '' ? 0 : score <= 1 ? 1 : score <= 3 ? 2 : 3;
            const labels = ['Enter a password', 'Weak', 'Medium', 'Strong'];
            const classes = ['', 'weak', 'medium', 'strong'];
            staffStrengthLabel.textContent = labels[level];
            staffStrengthLabel.className = classes[level];
            staffStrengthBar.className = classes[level];
            staffStrengthBar.style.width = (level * 33.33) + '%';
            if (staffStrengthHelp) staffStrengthHelp.textContent = level === 3 ? 'Strong password.' : 'Use at least 8 characters with uppercase, lowercase, number, and symbol.';
        });
    }

    const adminNewPassword = document.getElementById('new-password');
    const adminConfirmPassword = document.getElementById('confirm-password');
    const adminStrengthLabel = document.querySelector('[data-admin-strength-label]');
    const adminStrengthBar = document.querySelector('[data-admin-strength-bar]');
    const adminStrengthHelp = document.querySelector('[data-admin-strength-help]');
    if (adminNewPassword && adminConfirmPassword && adminStrengthLabel && adminStrengthBar) {
        const adminMatchMessage = document.createElement('small');
        adminMatchMessage.className = 'staff-password-match';
        adminConfirmPassword.parentElement.appendChild(adminMatchMessage);
        const updateAdminPasswordState = function () {
            let score = 0;
            if (adminNewPassword.value.length >= 8) score++;
            if (/[a-z]/.test(adminNewPassword.value) && /[A-Z]/.test(adminNewPassword.value)) score++;
            if (/\d/.test(adminNewPassword.value)) score++;
            if (/[^A-Za-z0-9]/.test(adminNewPassword.value)) score++;
            const level = adminNewPassword.value === '' ? 0 : score <= 1 ? 1 : score <= 3 ? 2 : 3;
            const labels = ['Enter a password', 'Weak', 'Medium', 'Strong'];
            const classes = ['', 'weak', 'medium', 'strong'];
            adminStrengthLabel.textContent = labels[level];
            adminStrengthLabel.className = classes[level];
            adminStrengthBar.className = classes[level];
            adminStrengthBar.style.width = (level * 33.33) + '%';
            if (adminStrengthHelp) adminStrengthHelp.textContent = level === 3 ? 'Strong password.' : 'Use at least 8 characters with uppercase, lowercase, number, and symbol.';
            if (!adminConfirmPassword.value) { adminMatchMessage.textContent = ''; adminMatchMessage.className = 'staff-password-match'; }
            else if (adminNewPassword.value === adminConfirmPassword.value) { adminMatchMessage.textContent = 'Passwords match.'; adminMatchMessage.className = 'staff-password-match matches'; }
            else { adminMatchMessage.textContent = 'Passwords do not match.'; adminMatchMessage.className = 'staff-password-match does-not-match'; }
        };
        adminNewPassword.addEventListener('input', updateAdminPasswordState);
        adminConfirmPassword.addEventListener('input', updateAdminPasswordState);
    }

    const staffDeleteModal = document.querySelector('[data-staff-delete-modal]');
    const staffDeleteBackdrop = document.querySelector('[data-staff-delete-backdrop]');
    const staffDeleteConfirm = document.querySelector('[data-staff-delete-confirm]');
    const staffDeleteSubmit = document.querySelector('[data-staff-delete-submit]');
    const closeStaffDelete = function () { if (staffDeleteModal) staffDeleteModal.classList.remove('is-open'); if (staffDeleteBackdrop) staffDeleteBackdrop.classList.remove('is-open'); };
    document.querySelectorAll('[data-staff-delete-open]').forEach(function (button) { button.addEventListener('click', function () { staffDeleteModal.classList.add('is-open'); staffDeleteBackdrop.classList.add('is-open'); staffDeleteConfirm.focus(); }); });
    document.querySelectorAll('[data-staff-delete-close]').forEach(function (button) { button.addEventListener('click', closeStaffDelete); });
    if (staffDeleteBackdrop) staffDeleteBackdrop.addEventListener('click', closeStaffDelete);
    if (staffDeleteConfirm && staffDeleteSubmit) staffDeleteConfirm.addEventListener('input', function () { staffDeleteSubmit.disabled = staffDeleteConfirm.value !== 'Delete'; });

    window.addEventListener('resize', function () {
        if (activeMenu && activeMenuButton) positionActionMenu(activeMenu, activeMenuButton);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeActionMenu();
        if (body.classList.contains('sidebar-open')) setSidebar(false);
        if (document.querySelector('.admin-modal.is-open')) closeModals();
    });
});
