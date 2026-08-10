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
    // Label lives in its own span so setLabel() can update the text without
    // wiping the sibling chevron. The chevron is an inline SVG (no icon-font
    // dependency, so the component stays portable) that CSS rotates on expand.
    var label = document.createElement('span');
    label.className = 'expandable-text__toggle-label';
    label.textContent = Drupal.t('More');
    btn.appendChild(label);
    btn.insertAdjacentHTML(
      'beforeend',
      '<svg class="expandable-text__chevron" width="14" height="14" viewBox="0 0 16 16" ' +
      'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
      'stroke-linejoin="round" aria-hidden="true" focusable="false">' +
      '<polyline points="4 6 8 10 12 6"></polyline></svg>'
    );
    return btn;
  }

  // Update a toggle button's visible label without disturbing the chevron.
  function setLabel(btn, text) {
    var label = btn.querySelector('.expandable-text__toggle-label');
    if (label) {
      label.textContent = text;
    }
  }

  // No-preference CSS transition is gated by prefers-reduced-motion; when the
  // user prefers reduced motion the max-height transition rule does not
  // apply, so `transitionend` never fires. Detect that case so animated
  // collapse/expand can run their cleanup synchronously instead of waiting
  // on an event that will never come.
  function hasMaxHeightTransition(content) {
    return parseFloat(getComputedStyle(content).transitionDuration) > 0;
  }

  function collapse(wrapper, content, height, animate) {
    wrapper.classList.add('is-collapsed');
    if (!animate) {
      content.style.maxHeight = height + 'px';
      content.setAttribute('inert', '');
      return;
    }
    if (!hasMaxHeightTransition(content)) {
      content.style.maxHeight = height + 'px';
      content.setAttribute('inert', '');
      return;
    }
    // Pin current full height, force reflow, then transition to collapsed height.
    content.style.maxHeight = content.scrollHeight + 'px';
    // eslint-disable-next-line no-unused-expressions
    content.offsetHeight; // force reflow so the browser has a start value
    content.style.maxHeight = height + 'px';
    // Make it inert only once it has finished collapsing (still interactive while animating shut).
    var onEnd = function (e) {
      if (e.propertyName !== 'max-height') { return; }
      content.setAttribute('inert', '');
      content.removeEventListener('transitionend', onEnd);
    };
    content.addEventListener('transitionend', onEnd);
  }

  function expand(wrapper, content, animate) {
    content.removeAttribute('inert');
    wrapper.classList.remove('is-collapsed');
    if (!animate) {
      content.style.maxHeight = '';
      return;
    }
    if (!hasMaxHeightTransition(content)) {
      content.style.maxHeight = '';
      return;
    }
    // Transition from the current collapsed px up to the full content height.
    var target = content.scrollHeight;
    content.style.maxHeight = target + 'px';
    var onEnd = function (e) {
      if (e.propertyName !== 'max-height') { return; }
      // Release to auto so later reflow (resize, font swap) isn't pinned to a stale px.
      content.style.maxHeight = '';
      content.removeEventListener('transitionend', onEnd);
    };
    content.addEventListener('transitionend', onEnd);
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
          expand(wrapper, content, true);
          btn.setAttribute('aria-expanded', 'true');
          setLabel(btn, Drupal.t('Less'));
        }
        else {
          collapse(wrapper, content, measure(content, n).height, true);
          btn.setAttribute('aria-expanded', 'false');
          setLabel(btn, Drupal.t('More'));
        }
      });
    }
    // A re-clamp (e.g. after a resize) may reuse an existing toggle that was
    // left in the expanded label state; force it back to the collapsed label.
    btn.setAttribute('aria-expanded', 'false');
    setLabel(btn, Drupal.t('More'));
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
