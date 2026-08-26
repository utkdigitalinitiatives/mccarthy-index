(function (Drupal) {
    // Keep in sync with the mobile breakpoint used in utk-sidebar.css.
    const mobileQuery = window.matchMedia('(max-width: 600px)');

    Drupal.behaviors.mccarthySidebarNav = {
        attach: function (context) {
            const toggle = context.querySelector('.sidebar-nav__toggle');
            const sidebar = context.querySelector('.sidebar-nav');
            const backdrop = context.querySelector('.sidebar-nav__backdrop');
            if (!toggle || !sidebar || toggle.dataset.sidebarNavBound) {
                return;
            }
            toggle.dataset.sidebarNavBound = 'true';

            function closeMobileOverlay() {
                sidebar.classList.remove('sidebar-nav--mobile-open');
                toggle.setAttribute('aria-expanded', 'false');
            }

            toggle.addEventListener('click', function () {
                if (mobileQuery.matches) {
                    const open = sidebar.classList.toggle('sidebar-nav--mobile-open');
                    toggle.setAttribute('aria-expanded', String(open));
                    return;
                }

                const collapsed = sidebar.classList.toggle('sidebar-nav--collapsed');
                toggle.setAttribute('aria-expanded', String(!collapsed));
            });

            if (backdrop) {
                backdrop.addEventListener('click', closeMobileOverlay);
            }

            // Drop the mobile overlay state if the viewport is resized past the breakpoint.
            mobileQuery.addEventListener('change', function (event) {
                if (!event.matches) {
                    closeMobileOverlay();
                }
            });
        },
    };
})(Drupal);

