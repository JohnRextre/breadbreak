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
            sidebarCollapse.querySelector('.collapse-icon').textContent = collapsed ? '»' : '«';
        }
        window.localStorage.setItem(sidebarPreferenceKey, collapsed ? 'collapsed' : 'expanded');
    };

    if (window.localStorage.getItem(sidebarPreferenceKey) === 'collapsed') setSidebarCollapsed(true);
    if (sidebarCollapse) sidebarCollapse.addEventListener('click', function () {
        setSidebarCollapsed(!body.classList.contains('sidebar-collapsed'));
    });

    const setSidebar = function (open) {
        body.classList.toggle('sidebar-open', open);
        if (menuToggle) {
            menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    };

    if (menuToggle) menuToggle.addEventListener('click', function () { setSidebar(true); });
    if (sidebarClose) sidebarClose.addEventListener('click', function () { setSidebar(false); });
    if (overlay) overlay.addEventListener('click', function () { setSidebar(false); });
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () { setSidebar(false); });
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
        button.addEventListener('click', function () {
            const input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            button.setAttribute('aria-label', input.type === 'password' ? 'Show password' : 'Hide password');
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
