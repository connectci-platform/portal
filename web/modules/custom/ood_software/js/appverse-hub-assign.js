/**
 * @file
 * The hub card's reviewer picker, submitted in place (D8-2931).
 *
 * Choosing a reviewer posts the form with fetch and updates the card's
 * assignee text, with scroll and filters untouched. A refusal (including a
 * 403 for an expired token) puts the select back and says why in the card's
 * polite live region. Without this script the form posts normally and
 * redirects back to the hub.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Shows a message in the card's live region.
   *
   * @param {HTMLElement} card
   *   The card.
   * @param {string} text
   *   The message.
   */
  function say(card, text) {
    const region = card.querySelector('[data-hub-assign-status]');
    if (region) {
      region.textContent = text;
    }
  }

  /**
   * Selects the option for a uid, or Unassigned when there is none.
   *
   * @param {HTMLSelectElement} select
   *   The picker.
   * @param {number|null} uid
   *   The assignee, or null.
   */
  function show(select, uid) {
    select.value = uid === null || uid === undefined ? '' : String(uid);
  }

  Drupal.behaviors.oodHubAssign = {
    attach(context) {
      once('ood-hub-assign', 'select[data-hub-assign-select]', context).forEach((select) => {
        const form = select.form;
        const card = select.closest('.appverse-hub-card') || document;
        let previous = select.value;
        let pending = false;

        select.addEventListener('change', () => {
          if (pending) {
            show(select, previous === '' ? null : previous);
            return;
          }
          const chosen = select.value;
          pending = true;
          select.disabled = true;
          say(card, '');
          fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            body: new URLSearchParams({ uid: chosen }),
          })
            .then((response) => response.json()
              .catch(() => ({}))
              .then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
              if (!ok) {
                select.value = previous;
                say(card, data.message || Drupal.t('The reviewer could not be changed. Reload the page and try again.'));
                return;
              }
              previous = chosen;
              const name = card.querySelector('[data-hub-assignee]');
              if (name) {
                name.textContent = data.assignee ? data.assignee.name : Drupal.t('Unassigned');
              }
              say(card, data.message || '');
            })
            .catch(() => {
              select.value = previous;
              say(card, Drupal.t('The reviewer could not be changed. Reload the page and try again.'));
            })
            .finally(() => {
              pending = false;
              select.disabled = false;
            });
        });
      });
    },
  };
}(Drupal, once));
