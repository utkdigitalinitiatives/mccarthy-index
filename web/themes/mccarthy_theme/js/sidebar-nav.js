(function (Drupal) {
    Drupal.behaviors.mccarthySidebarNav = {
        attach: function (context) {
            const toggle = context.querySelector('.sidebar-nav__toggle');
            const sidebar = context.querySelector('.sidebar-nav');
            if (!toggle || !sidebar || toggle.dataset.sidebarNavBound) {
                return;
            }
            toggle.dataset.sidebarNavBound = 'true';

            toggle.addEventListener('click', function () {
                const collapsed = sidebar.classList.toggle('sidebar-nav--collapsed');
                toggle.setAttribute('aria-expanded', String(!collapsed));
            });
        },
    };
})(Drupal);
