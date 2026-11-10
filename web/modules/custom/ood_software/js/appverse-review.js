/**
 * @file
 * Review page: per-finding note boxes, the per-axis override box, and the
 * worklists' add forms. Everything is plain markup that works without JS (all
 * boxes open, each add form at the end of its list); JS only collapses and
 * moves.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.appverseReview = {
    attach(context) {
      // The send button names the decision it will send and waits until every
      // app has one and, unless all are Accept, a response is written
      // (appverse-planning#52). The server sets the same from the saved page;
      // this keeps it current as the reviewer chooses.
      once('arv-send', '.arv-page .arv-send', context).forEach((button) => {
        const form = button.form;
        const labels = JSON.parse(button.dataset.labels || '{}');
        const reasons = JSON.parse(button.dataset.reasons || '{}');
        const reason = document.getElementById(button.getAttribute('aria-describedby'));
        const selects = form.querySelectorAll('select[name^="conclusion["]');
        const response = form.querySelector('textarea[name="response"]');
        const order = ['accept', 'accept_with_suggestions', 'request_changes', 'reject'];
        // Drupal renders the submit as an input, whose label is its value.
        const setLabel = (text) => {
          if (button.tagName === 'INPUT') {
            button.value = text;
          }
          else {
            button.textContent = text;
          }
        };
        const update = () => {
          const chosen = Array.from(selects, (s) => s.value);
          const decided = chosen.filter((v) => order.includes(v));
          const distinct = [...new Set(decided)];
          if (distinct.length === 0) {
            setLabel(labels.none);
          }
          else if (distinct.length === 1) {
            setLabel(labels.single[distinct[0]]);
          }
          else {
            const parts = order
              .filter((d) => distinct.includes(d))
              .map((d) => labels.count[d].replace('@count', decided.filter((v) => v === d).length));
            setLabel(labels.mixed.replace('@summary', parts.join(', ')));
          }
          let why = '';
          if (decided.length < chosen.length || chosen.length === 0) {
            why = reasons.undecided;
          }
          else if (distinct.some((d) => d !== 'accept') && (!response || response.value.trim() === '')) {
            why = reasons.response;
          }
          // An app being accepted needs its duplicate check recorded (A4).
          else if (Array.from(selects).some((s) => {
            const pid = (s.name.match(/^conclusion\[(\d+)\]$/) || [])[1];
            return ['accept', 'accept_with_suggestions'].includes(s.value)
              && pid && !form.querySelector(`input[name="duplicate[${pid}][outcome]"]:checked`);
          })) {
            why = reasons.duplicate;
          }
          button.disabled = why !== '';
          if (reason) {
            reason.textContent = why;
          }
        };
        selects.forEach((s) => s.addEventListener('change', update));
        if (response) {
          response.addEventListener('input', update);
        }
        form.querySelectorAll('input[name^="duplicate["]').forEach((r) => r.addEventListener('change', update));
        // The server disables the button from the saved review, so after a
        // failed send it can be stale against what the reviewer posted.
        update();
      });

      // Long evidence clamps to two lines with a toggle. OODT-05 cites a
      // dozen file:line references and filled a third of its card
      // (appverse-planning#53). The threshold is characters rather than
      // measured height: it reads the same before and after layout, and
      // avoids a reflow on every finding.
      once('arv-evidence', '.arv-page .frow .ev', context).forEach((ev) => {
        if (ev.textContent.trim().length <= 90) {
          return;
        }
        ev.classList.add('ev--clamped');
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'ev-toggle';
        toggle.textContent = Drupal.t('Show all');
        toggle.setAttribute('aria-expanded', 'false');
        ev.after(toggle);
        toggle.addEventListener('click', () => {
          const clamped = ev.classList.toggle('ev--clamped');
          toggle.textContent = clamped ? Drupal.t('Show all') : Drupal.t('Show less');
          toggle.setAttribute('aria-expanded', clamped ? 'false' : 'true');
        });
      });

      // A finding's edit box starts closed; the note and any change show in
      // the row itself. It stays open when it holds a validation error.
      once('arv-note', '.arv-page .finding', context).forEach((finding) => {
        const box = finding.querySelector('.fnote');
        const hint = finding.querySelector('.expand-hint');
        if (!box || !hint) {
          return;
        }
        const fields = box.querySelectorAll('input, select, textarea');
        const setOpen = (open) => {
          box.classList.toggle('collapsed', !open);
          hint.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        setOpen(box.querySelector('.error') !== null);
        hint.addEventListener('click', (e) => {
          e.stopPropagation();
          const open = box.classList.contains('collapsed');
          setOpen(open);
          if (open && fields.length) {
            fields[0].focus();
          }
        });
        // Cancel puts every field back as the page loaded it, so a later
        // Save draft does not save what was cancelled.
        const cancel = box.querySelector('.finding-cancel');
        if (cancel) {
          cancel.addEventListener('click', () => {
            fields.forEach((el) => {
              if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = el.defaultChecked;
              }
              else if (el.tagName === 'SELECT') {
                Array.from(el.options).forEach((o) => { o.selected = o.defaultSelected; });
              }
              else if (el.type !== 'submit' && el.type !== 'button') {
                el.value = el.defaultValue;
              }
            });
            setOpen(false);
            hint.focus();
          });
        }
      });

      // The rating shows as a badge until the edit icon is clicked, which
      // swaps in the dropdown and the note (appverse-planning#54). The markup
      // carries both and starts with the dropdown shown, so without this the
      // page still works; the class added here is what hides one of them.
      // Each rating in a list's ratings line has its own icon.
      once('arv-override', '.arv-page .arv-ratings .arv-rating', context).forEach((block) => {
        const btn = block.querySelector('.edit-icon-btn');
        const box = block.querySelector('.override-box');
        const readonly = block.querySelector('.level-readonly');
        const control = block.querySelector('.level-control');
        if (!btn) {
          return;
        }
        const textarea = box ? box.querySelector('textarea') : null;
        // Already changed, or carrying a note: leave it open, because that is
        // the reviewer's own work and hiding it would lose them.
        const changed = block.classList.contains('level-changed')
          || (textarea && textarea.value.trim() !== '');
        if (!changed) {
          if (box) {
            box.classList.add('collapsed');
          }
          if (readonly && control) {
            block.classList.add('level-collapsed');
          }
        }
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          if (box) {
            box.classList.toggle('collapsed');
          }
          block.classList.toggle('level-collapsed');
          const select = control ? control.querySelector('select') : null;
          if (select && !block.classList.contains('level-collapsed')) {
            select.focus();
          }
        });
      });

      // Picking a rule moves the add form's section to the rule's own, so an
      // OODT code is not filed under Code quality by accident.
      once('arv-add-rule', '.arv-page select[data-rule-sections]', context).forEach((select) => {
        const map = JSON.parse(select.dataset.ruleSections || '{}');
        const fields = select.closest('.finding-fields');
        const section = fields ? fields.querySelector('select.arv-add-section') : null;
        if (!section) {
          return;
        }
        select.addEventListener('change', () => {
          if (map[select.value]) {
            section.value = map[select.value];
          }
        });
      });

      // "Add a finding here": the list's add form moves under the row, on the
      // row's section, open, with the summary focused. Without JS the button
      // stays hidden and the form sits at the end of the list.
      once('arv-add-here', '.arv-page .arv-add-here', context).forEach((btn) => {
        const list = btn.closest('.arv-worklist');
        const fold = list ? list.querySelector('details.add-finding') : null;
        const row = btn.closest('.finding');
        if (!fold || !row) {
          return;
        }
        btn.hidden = false;
        btn.addEventListener('click', () => {
          const section = fold.querySelector('select.arv-add-section');
          if (section && Array.from(section.options).some((o) => o.value === btn.dataset.section)) {
            section.value = btn.dataset.section;
          }
          row.after(fold);
          fold.open = true;
          const summary = fold.querySelector('.arv-add-summary');
          if (summary) {
            summary.focus();
          }
        });
      });
    },
  };
})(Drupal, once);
