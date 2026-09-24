(function (Drupal) {
  'use strict';

  Drupal.behaviors.disableViewsAjaxScroll = {
    attach: function (context, settings) {
      if (typeof Drupal.ajax !== 'undefined' && typeof Drupal.AjaxCommands !== 'undefined') {
        // Disable the default views scroll top command globally
        Drupal.AjaxCommands.prototype.viewsScrollTop = function (ajax, response, status) {
          // Do nothing, preventing the scroll behavior
        };
      }
    }
  };
})(Drupal);