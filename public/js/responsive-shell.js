(() => {
    const shell = document.getElementById('appShell');
    const sidebar = document.getElementById('appSidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];

    if (!shell || !sidebar || toggles.length === 0) return;

    const mobileMedia = window.matchMedia('(max-width: 900px)');
    const desktopStateKey = 'sutoorii:tickets:sidebar-collapsed';

    const readCollapsed = () => {
        try {
            return localStorage.getItem(desktopStateKey) === '1';
        } catch (_) {
            return false;
        }
    };

    const saveCollapsed = collapsed => {
        try {
            localStorage.setItem(desktopStateKey, collapsed ? '1' : '0');
        } catch (_) {}
    };

    const syncAria = open => {
        toggles.forEach(toggle => toggle.setAttribute('aria-expanded', String(open)));
    };

    const closeMobile = () => {
        shell.classList.remove('sidebar-open');
        document.body.classList.remove('sidebar-open');
        syncAria(false);
    };

    const openMobile = () => {
        shell.classList.add('sidebar-open');
        document.body.classList.add('sidebar-open');
        syncAria(true);
    };

    const applyDesktopState = () => {
        const collapsed = readCollapsed();
        shell.classList.toggle('sidebar-collapsed', collapsed);
        syncAria(!collapsed);
    };

    const toggleSidebar = () => {
        if (mobileMedia.matches) {
            shell.classList.contains('sidebar-open') ? closeMobile() : openMobile();
            return;
        }

        const collapsed = !shell.classList.contains('sidebar-collapsed');
        shell.classList.toggle('sidebar-collapsed', collapsed);
        saveCollapsed(collapsed);
        syncAria(!collapsed);
    };

    toggles.forEach(toggle => toggle.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        toggleSidebar();
    }));

    backdrop?.addEventListener('click', closeMobile);

    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (mobileMedia.matches) closeMobile();
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeMobile();
    });

    const handleViewportChange = () => {
        closeMobile();
        if (mobileMedia.matches) {
            shell.classList.remove('sidebar-collapsed');
        } else {
            applyDesktopState();
        }
    };

    if (typeof mobileMedia.addEventListener === 'function') {
        mobileMedia.addEventListener('change', handleViewportChange);
    } else if (typeof mobileMedia.addListener === 'function') {
        mobileMedia.addListener(handleViewportChange);
    }

    handleViewportChange();
})();
