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
