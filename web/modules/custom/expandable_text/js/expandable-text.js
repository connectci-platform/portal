(function (Drupal, once) {
  'use strict';

  /**
   * Measure the collapsed height for N rendered lines using Range rects.
   * Returns { overflow: false } when content is <= N lines, else
   * { overflow: true, height: <px from content-box top to Nth line bottom> }.
   */
  function measure(content, n) {
    var walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
    var tops = {};
    var rects = [];
    var node;
    while ((node = walker.nextNode())) {
      var range = document.createRange();
      for (var i = 0; i < node.length; i++) {
        range.setStart(node, i);
        range.setEnd(node, i + 1);
        var rc = range.getBoundingClientRect();
        if (rc.height === 0) {
          continue;
        }
        var key = Math.round(rc.top);
        if (!tops[key]) {
          tops[key] = true;
          rects.push(rc);
        }
      }
    }
    rects.sort(function (a, b) { return a.top - b.top; });
    if (rects.length <= n) {
      return { overflow: false };
    }
    var contentTop = content.getBoundingClientRect().top;
    return { overflow: true, height: rects[n - 1].bottom - contentTop };
  }

  function makeToggle(contentId) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'expandable-text__toggle';
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', contentId);
    btn.textContent = Drupal.t('More');
    return btn;
  }

  function collapse(wrapper, content, height) {
    content.style.maxHeight = height + 'px';
    content.setAttribute('inert', '');
    wrapper.classList.add('is-collapsed');
  }

  function expand(wrapper, content) {
    content.style.maxHeight = '';
    content.removeAttribute('inert');
    wrapper.classList.remove('is-collapsed');
  }

  function apply(wrapper) {
    var content = wrapper.querySelector('.expandable-text__content');
    if (!content) {
      return;
    }
    // Measure-while-hidden guard: bail if not laid out; retry when shown.
    if (wrapper.offsetParent === null && wrapper.getClientRects().length === 0) {
      if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries, obs) {
          if (entries[0].isIntersecting) {
            obs.disconnect();
            apply(wrapper);
          }
        });
        io.observe(wrapper);
      }
      return;
    }

    var n = parseInt(wrapper.getAttribute('data-lines'), 10) || 4;
    var result = measure(content, n);
    if (!result.overflow) {
      return;
    }

    var btn = wrapper.querySelector('.expandable-text__toggle');
    if (!btn) {
      btn = makeToggle(content.id);
      wrapper.appendChild(btn);
      btn.addEventListener('click', function () {
        if (wrapper.classList.contains('is-collapsed')) {
          expand(wrapper, content);
          btn.setAttribute('aria-expanded', 'true');
          btn.textContent = Drupal.t('Less');
        }
        else {
          collapse(wrapper, content, measure(content, n).height);
          btn.setAttribute('aria-expanded', 'false');
          btn.textContent = Drupal.t('More');
        }
      });
    }
    collapse(wrapper, content, result.height);
  }

  Drupal.behaviors.expandableText = {
    attach: function (context) {
      var wrappers = once('expandable-text', '.expandable-text', context);
      wrappers.forEach(function (wrapper) {
        apply(wrapper);
        // Re-measure after fonts load (line rects shift on font swap).
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(function () {
            if (!wrapper.classList.contains('is-collapsed') &&
                !wrapper.querySelector('.expandable-text__toggle')) {
              apply(wrapper);
            }
          });
        }
      });

      // Debounced resize re-measure; skip expanded wrappers so a resize
      // while reading does not snap them shut.
      if (!Drupal.behaviors.expandableText._resizeBound) {
        Drupal.behaviors.expandableText._resizeBound = true;
        var timer = null;
        window.addEventListener('resize', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            document.querySelectorAll('.expandable-text.is-collapsed').forEach(function (wrapper) {
              var content = wrapper.querySelector('.expandable-text__content');
              var n = parseInt(wrapper.getAttribute('data-lines'), 10) || 4;
              var r = measure(content, n);
              if (r.overflow) {
                content.style.maxHeight = r.height + 'px';
              }
            });
          }, 150);
        });
      }
    }
  };
})(Drupal, once);
