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
      // Content fits in N lines. If this wrapper was previously clamped
      // (collapsed) or still carries a toggle from an earlier overflow state,
      // un-clamp it and drop the now-dead toggle. Idempotent for wrappers that
      // were never clamped.
      var existing = wrapper.querySelector('.expandable-text__toggle');
      if (existing) {
        existing.remove();
      }
      expand(wrapper, content);
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
    // A re-clamp (e.g. after a resize) may reuse an existing toggle that was
    // left in the expanded label state; force it back to the collapsed label.
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = Drupal.t('More');
    collapse(wrapper, content, result.height);
  }

  Drupal.behaviors.expandableText = {
    attach: function (context) {
      var wrappers = once('expandable-text', '.expandable-text', context);
      wrappers.forEach(function (wrapper) {
        apply(wrapper);
        // Re-measure after fonts load (line rects shift on font swap). apply()
        // is symmetric: it clamps if the wrapper now overflows and un-clamps if
        // it now fits. Skip only wrappers the user has actively expanded.
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(function () {
            var t = wrapper.querySelector('.expandable-text__toggle');
            if (t && t.getAttribute('aria-expanded') === 'true') {
              return;
            }
            apply(wrapper);
          });
        }
      });

      // Debounced resize re-measure; every wrapper that is NOT currently
      // user-expanded is reconciled through apply(), so a resize while the
      // user reads an expanded wrapper never snaps it shut, while collapsed
      // and previously-un-clamped wrappers stay correct in both directions.
      if (!Drupal.behaviors.expandableText._resizeBound) {
        Drupal.behaviors.expandableText._resizeBound = true;
        var timer = null;
        window.addEventListener('resize', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            document.querySelectorAll('.expandable-text').forEach(function (wrapper) {
              // Skip wrappers the user has actively expanded (toggle shows
              // "Less" / aria-expanded=true) so a resize while reading does not
              // snap them shut. Everything else is re-evaluated: collapsed
              // wrappers re-measure their height, and wrappers previously
              // un-clamped because they fit get re-clamped if they now overflow.
              var t = wrapper.querySelector('.expandable-text__toggle');
              if (t && t.getAttribute('aria-expanded') === 'true') {
                return;
              }
              apply(wrapper);
            });
          }, 150);
        });
      }
    }
  };
})(Drupal, once);
