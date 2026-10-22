/**
 * @file
 * Randomizes the order of people in a people grid, in the browser.
 *
 * Server-side caching can't vary the order per visit, so lists marked with
 * [data-shuffle] are shuffled here. Visitors without JavaScript see the
 * server order.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.peopleGridShuffle = {
    attach: function (context) {
      once('people-grid-shuffle', '[data-shuffle]', context).forEach(function (list) {
        var items = Array.prototype.slice.call(list.children);

        // Fisher-Yates shuffle.
        for (var i = items.length - 1; i > 0; i--) {
          var j = Math.floor(Math.random() * (i + 1));
          var tmp = items[i];
          items[i] = items[j];
          items[j] = tmp;
        }

        // Re-appending moves the existing nodes; no markup is rebuilt.
        items.forEach(function (item) {
          list.appendChild(item);
        });

        list.classList.add('is-shuffled');

        var scroller = list.closest('.people-grid-block');
        if (scroller) {
          scroller.scrollTop = 0;
        }
      });
    }
  };

})(Drupal, once);
