(function (Drupal) {
  'use strict';

  Drupal.behaviors.disableSearchViewAjaxScroll = {
    attach: function () {
      if (typeof Drupal.AjaxCommands === 'undefined') {
        return;
      }

      const commands = Drupal.AjaxCommands.prototype;
      if (typeof commands.scrollTop !== 'function' || commands.scrollTop._searchViewScrollOverride) {
        return;
      }

      const originalScrollTop = commands.scrollTop;
      commands.scrollTop = function (ajax, response) {
        const target = response.selector && document.querySelector(response.selector);
        if (target && target.matches('.view-id-records')) {
          return;
        }

        return originalScrollTop.call(this, ajax, response);
      };
      commands.scrollTop._searchViewScrollOverride = true;
    }
  };
})(Drupal);