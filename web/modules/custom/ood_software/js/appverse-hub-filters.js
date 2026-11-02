/**
 * @file
 * All and Clear links under the Manage Repos filter boxes (D8-2931).
 *
 * The links are rendered next to a multi-select, named by data-select-all and
 * data-select-clear. Select2 redraws its chips on a change event, so the
 * script fires one after changing the options. The moderation All link
 * keeps an href to ?moderation_state=All, so it still works without this
 * script; the other links stay hidden until it runs.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Finds the select for an exposed filter inside a form.
   *
   * @param {HTMLElement} form
   *   The exposed form.
   * @param {string} name
   *   The filter identifier.
   *
   * @return {HTMLSelectElement|null}
   *   The select, or null.
   */
  function findSelect(form, name) {
    return form.querySelector('select[name="' + name + '[]"], select[name="' + name + '"]');
  }

  /**
   * Sets which options are selected, then tells Select2 and listeners.
   *
   * @param {HTMLSelectElement} select
   *   The select.
   * @param {boolean} selected
   *   Whether to select the options.
   * @param {string[]} skip
   *   Option values to leave unselected.
   */
  function setAll(select, selected, skip) {
    Array.from(select.options).forEach(function (option) {
      const pickable = option.value !== '' && option.value !== 'All' && !skip.includes(option.value);
      option.selected = selected && pickable;
    });
    // jQuery handlers (Select2's) receive a native event too.
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }

  Drupal.behaviors.appverseHubFilters = {
    attach(context) {
      once('appverse-hub-filters', '[data-select-all], [data-select-clear]', context).forEach(function (link) {
        const all = link.hasAttribute('data-select-all');
        const name = link.getAttribute(all ? 'data-select-all' : 'data-select-clear');
        const skip = (link.getAttribute('data-select-skip') || '').split(',').filter(Boolean);
        const wrapper = link.closest('.appverse-hub-select-links');
        if (wrapper) {
          wrapper.hidden = false;
          wrapper.querySelectorAll('[hidden]').forEach(function (part) {
            part.hidden = false;
          });
        }
        link.addEventListener('click', function (event) {
          const form = link.closest('form');
          const select = form && findSelect(form, name);
          if (!select) {
            return;
          }
          event.preventDefault();
          setAll(select, all, skip);
        });
      });
    },
  };
}(Drupal, once));
