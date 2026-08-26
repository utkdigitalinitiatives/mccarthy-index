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

            // Mobile reuses the icon-rail "collapsed" look as its resting state;
            // opening trades --collapsed for --mobile-open (fixed overlay).
            function closeMobileOverlay() {
                sidebar.classList.remove('sidebar-nav--mobile-open');
                sidebar.classList.add('sidebar-nav--collapsed');
                toggle.setAttribute('aria-expanded', 'false');
            }

            function syncForViewport() {
                if (mobileQuery.matches) {
                    closeMobileOverlay();
                } else {
                    sidebar.classList.remove('sidebar-nav--mobile-open');
                }
            }

            toggle.addEventListener('click', function () {
                if (mobileQuery.matches) {
                    const opening = !sidebar.classList.contains('sidebar-nav--mobile-open');
                    sidebar.classList.toggle('sidebar-nav--mobile-open', opening);
                    sidebar.classList.toggle('sidebar-nav--collapsed', !opening);
                    toggle.setAttribute('aria-expanded', String(opening));
                    return;
                }

                const collapsed = sidebar.classList.toggle('sidebar-nav--collapsed');
                toggle.setAttribute('aria-expanded', String(!collapsed));
            });

            if (backdrop) {
                backdrop.addEventListener('click', closeMobileOverlay);
            }

            mobileQuery.addEventListener('change', syncForViewport);
            syncForViewport();
        },
    };
})(Drupal);


