/**
 * @file
 * Review page: per-finding note boxes, the per-axis override box, and the
 * worklists' add forms. Everything is plain markup that works without JS (all
 * boxes open, each add form at the end of its list); JS only collapses and
 * moves.
 *
 * A finding's Save, Add and Delete post over AJAX and replace the whole form
 * (#arv-review-form). What the reviewer had open is recorded when one is
 * pressed and put back on the new form, and while the request is out the
 * text fields are read-only, so nothing typed meanwhile is lost.
 */
(function (Drupal, once) {
  'use strict';

  const WRAPPER = 'arv-review-form';
  const ERROR = '.error, [aria-invalid="true"]';

  // What was open when a save was pressed, for the form that replaces it.
  let pending = null;
  // The findings whose edit box the arv-note behavior opens on this attach.
  let reopen = new Set();

  /**
   * The open folds and edit boxes, and where a moved add form sits.
   *
   * @param {HTMLElement} wrapper
   *   The form's wrapper.
   *
   * @return {object}
   *   The state, by element id.
   */
  function record(wrapper) {
    const state = { folds: [], boxes: [], after: {} };
    wrapper.querySelectorAll('details[id^="fold-"]').forEach((fold) => {
      if (fold.open) {
        state.folds.push(fold.id);
      }
      // Moved by "Add a finding here": put back after the same row.
      if (fold.dataset.arvAfter) {
        state.after[fold.id] = fold.dataset.arvAfter;
      }
    });
    wrapper.querySelectorAll('.finding[id]').forEach((finding) => {
      const box = finding.querySelector('.fnote');
      if (box && !box.classList.contains('collapsed')) {
        state.boxes.push(finding.id);
      }
    });
    return state;
  }

  /**
   * Puts the recorded state back on the form that replaced it.
   *
   * @param {object} state
   *   From record().
   */
  function restore(state) {
    Object.keys(state.after).forEach((id) => {
      const fold = document.getElementById(id);
      const row = document.getElementById(state.after[id]);
      if (fold && row) {
        row.after(fold);
        fold.dataset.arvAfter = row.id;
      }
    });
    state.folds.forEach((id) => {
      const fold = document.getElementById(id);
      if (fold) {
        fold.open = true;
      }
    });
    reopen = new Set(state.boxes);
  }

  /**
   * Marks the form busy and its text read-only while a save is out.
   *
   * @param {HTMLElement} wrapper
   *   The form's wrapper.
   * @param {boolean} busy
   *   Whether a save is in flight.
   */
  function lock(wrapper, busy) {
    if (busy) {
      wrapper.setAttribute('aria-busy', 'true');
    }
    else {
      wrapper.removeAttribute('aria-busy');
    }
    wrapper.querySelectorAll('input[type="text"], textarea').forEach((field) => {
      if (busy && !field.readOnly) {
        field.readOnly = true;
        field.dataset.arvLocked = 'true';
      }
      else if (!busy && field.dataset.arvLocked) {
        field.readOnly = false;
        delete field.dataset.arvLocked;
      }
    });
    // Nothing else can be pressed or changed while the save is out. The
    // request is serialized before beforeSend runs, so disabling here does
    // not drop any value, and the pressed button travels as extra data.
    wrapper.querySelectorAll('select, input[type="checkbox"], input[type="radio"], input[type="submit"], button[type="submit"]').forEach((control) => {
      if (busy && !control.disabled) {
        control.disabled = true;
        control.dataset.arvDisabled = 'true';
      }
      else if (!busy && control.dataset.arvDisabled) {
        control.disabled = false;
        delete control.dataset.arvDisabled;
      }
    });
  }

  /**
   * Hooks Drupal's AJAX for the form's own buttons, once per page.
   */
  function hookAjax() {
    if (!Drupal.Ajax || Drupal.Ajax.prototype.arvHooked) {
      return;
    }
    const proto = Drupal.Ajax.prototype;
    const { beforeSend, success, error } = proto;
    proto.arvHooked = true;
    proto.beforeSend = function arvBeforeSend(...args) {
      const wrapper = this.element && this.element.closest ? this.element.closest(`#${WRAPPER}`) : null;
      if (wrapper) {
        pending = record(wrapper);
        lock(wrapper, true);
        this.arvWrapper = wrapper;
      }
      return beforeSend.apply(this, args);
    };
    proto.success = function arvSuccess(...args) {
      const result = success.apply(this, args);
      if (this.arvWrapper) {
        const old = this.arvWrapper;
        this.arvWrapper = null;
        // The form is normally replaced; if it was not, unlock it.
        const done = () => {
          lock(old, false);
          pending = null;
        };
        Promise.resolve(result).then(done, done);
      }
      return result;
    };
    proto.error = function arvError(...args) {
      if (this.arvWrapper) {
        lock(this.arvWrapper, false);
        this.arvWrapper = null;
        pending = null;
      }
      return error.apply(this, args);
    };
  }

  Drupal.behaviors.appverseReview = {
    attach(context) {
      hookAjax();
      // The form a save replaced: open what was open.
      const wrapper = context.id === WRAPPER ? context : (context.querySelector && context.querySelector(`#${WRAPPER}`));
      reopen = new Set();
      if (pending && wrapper) {
        restore(pending);
        pending = null;
      }
      // An error is never hidden: open every fold that holds one. The edit
      // boxes holding one open below.
      if (context.querySelectorAll) {
        context.querySelectorAll(ERROR).forEach((field) => {
          let fold = field.closest('details');
          while (fold) {
            fold.open = true;
            fold = fold.parentElement ? fold.parentElement.closest('details') : null;
          }
        });
      }

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
      // the row itself. It stays open when it holds a validation error, or
      // was open when a save replaced the form.
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
        // A save that went through leaves its own row's box closed (.arv-saved
        // renders only there); a refused one keeps it open.
        setOpen(box.querySelector(ERROR) !== null || (reopen.has(finding.id) && !finding.querySelector('.arv-saved')));
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

      // The add form's rule and section follow each other. Picking a rule
      // moves the section to the rule's own, so an OODT code is not filed
      // under Code quality by accident; moving the section (by hand or by
      // "Add a finding here") starts the rule on that section's first, unless
      // the rule is already one of its own or Other.
      once('arv-add-rule', '.arv-page select[data-rule-sections]', context).forEach((rule) => {
        const map = JSON.parse(rule.dataset.ruleSections || '{}');
        const fields = rule.closest('.finding-fields');
        const section = fields ? fields.querySelector('select.arv-add-section') : null;
        if (!section) {
          return;
        }
        rule.addEventListener('change', () => {
          if (map[rule.value]) {
            section.value = map[rule.value];
          }
        });
        section.addEventListener('change', () => {
          if (!map[rule.value] || map[rule.value] === section.value) {
            return;
          }
          const first = Object.keys(map).find((code) => map[code] === section.value);
          rule.value = first || '_other';
          rule.dispatchEvent(new Event('change', { bubbles: true }));
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
            // So the rule follows the section.
            section.dispatchEvent(new Event('change', { bubbles: true }));
          }
          row.after(fold);
          // So a save that replaces the form puts it back here.
          fold.dataset.arvAfter = row.id;
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
