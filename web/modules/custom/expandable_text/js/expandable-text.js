(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.expandableText = {
    attach: function (context) {
      once('expandable-text', '.expandable-text', context);
    }
  };
})(Drupal, once);
