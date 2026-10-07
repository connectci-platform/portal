/**
 * @file
 * Review page: collapsible severity groups, per-finding note boxes, and the
 * per-axis override box. Mirrors the wireframe's interactions; everything is
 * plain markup that works without JS (all boxes open), JS only collapses.
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
        const update = () => {
          const chosen = Array.from(selects, (s) => s.value);
          const decided = chosen.filter((v) => order.includes(v));
          const distinct = [...new Set(decided)];
          if (distinct.length === 0) {
            button.textContent = labels.none;
          }
          else if (distinct.length === 1) {
            button.textContent = labels.single[distinct[0]];
          }
          else {
            const parts = order
              .filter((d) => distinct.includes(d))
              .map((d) => labels.count[d].replace('@count', decided.filter((v) => v === d).length));
            button.textContent = labels.mixed.replace('@summary', parts.join(', '));
          }
          let why = '';
          if (decided.length < chosen.length || chosen.length === 0) {
            why = reasons.undecided;
          }
          else if (distinct.some((d) => d !== 'accept') && (!response || response.value.trim() === '')) {
            why = reasons.response;
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
      });

      once('arv-sev', '.arv-page .sev-head', context).forEach((head) => {
        const body = head.nextElementSibling;
        if (!body) {
          return;
        }
        // Collapse everything but the worst group by default, as the wireframe.
        if (!head.classList.contains('sev-head--open')) {
          body.classList.add('collapsed');
        }
        head.addEventListener('click', () => {
          body.classList.toggle('collapsed');
          head.classList.toggle('sev-head--open');
        });
      });

      once('arv-note', '.arv-page .finding', context).forEach((finding) => {
        const box = finding.querySelector('.fnote');
        const hint = finding.querySelector('.expand-hint');
        if (!box || !hint) {
          return;
        }
        const textarea = box.querySelector('textarea');
        const hasText = textarea ? textarea.value.trim() !== '' : box.textContent.trim() !== '';
        // A box holding a validation error stays open so the error is seen.
        const hasError = box.querySelector('.error') !== null;
        if (!hasText && !hasError) {
          box.classList.add('collapsed');
        }
        else if (hint.dataset.hasNote) {
          // Automated findings say "has note"; a reviewer's own keeps "Edit".
          hint.textContent = hint.dataset.hasNote;
        }
        hint.addEventListener('click', (e) => {
          e.stopPropagation();
          box.classList.toggle('collapsed');
          if (textarea && !box.classList.contains('collapsed')) {
            textarea.focus();
          }
        });
      });

      once('arv-override', '.arv-page .catblock', context).forEach((block) => {
        const btn = block.querySelector('.edit-icon-btn');
        const box = block.querySelector('.override-box');
        if (!btn || !box) {
          return;
        }
        const textarea = box.querySelector('textarea');
        if (!textarea || textarea.value.trim() === '') {
          box.classList.add('collapsed');
        }
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          box.classList.toggle('collapsed');
        });
      });
    },
  };
})(Drupal, once);
