(function (Drupal) {
    Drupal.behaviors.mccarthyBackToTop = {
        attach: function (context) {
            const button = context.querySelector('#back-to-top');
            if (!button || button.dataset.backToTopBound) {
                return;
            }
            button.dataset.backToTopBound = 'true';

            button.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        },
    };
})(Drupal);
