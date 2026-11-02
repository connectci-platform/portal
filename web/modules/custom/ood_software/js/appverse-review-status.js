/**
 * @file
 * Live AI report status for the review page and in-flight hub cards (D8-2931).
 *
 * Polls the reviewer-only review-status endpoint every 30 seconds while a run
 * is in flight, and patches the page in place when it finishes. It pauses
 * while the tab is hidden, never starts a request while one is pending, and
 * gives up after the server's stale timeout. Nothing is polled when no run
 * was in flight at render, since only those pages carry the hooks below.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const INTERVAL_MS = 30000;
  // Mirrors AppverseReviewService::STALE_TIMEOUT_SECONDS.
  const STALE_TIMEOUT_MS = 7200 * 1000;

  /**
   * Polls one URL until the run is no longer in flight.
   *
   * @param {string} url
   *   The review-status endpoint.
   * @param {function} onUpdate
   *   Called with each response's data.
   */
  function startPoller(url, onUpdate) {
    const startedAt = Date.now();
    let pending = false;
    let timer = null;
    let stopped = false;

    function stop() {
      stopped = true;
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', tick);
    }

    function tick() {
      if (stopped || pending || document.hidden) {
        return;
      }
      if (Date.now() - startedAt >= STALE_TIMEOUT_MS) {
        stop();
        return;
      }
      pending = true;
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : null))
        .then((data) => {
          if (!data) {
            return;
          }
          onUpdate(data);
          if (!data.in_flight) {
            stop();
          }
        })
        .catch(() => {})
        .finally(() => {
          pending = false;
        });
    }

    timer = window.setInterval(tick, INTERVAL_MS);
    // A tab that was hidden past its interval catches up on return.
    document.addEventListener('visibilitychange', tick);
  }

  function reveal(element) {
    if (element) {
      element.hidden = false;
    }
  }

  Drupal.behaviors.oodReviewStatus = {
    attach(context) {
      const settings = drupalSettings.oodReviewStatus;
      if (settings && settings.inFlight) {
        once('ood-review-status', '[data-review-run-status]', context).forEach((status) => {
          const page = status.closest('.review-history') || document;
          startPoller(settings.url, (data) => {
            if (data.in_flight) {
              return;
            }
            status.textContent = data.status === 'complete'
              ? Drupal.t('AI report finished')
              : Drupal.t('AI report failed');
            const rerun = page.querySelector('[data-review-rerun]');
            const fresh = page.querySelector('[data-review-new]');
            if (data.latest_review === settings.review) {
              reveal(rerun);
            }
            else if (fresh && data.latest_review_url) {
              // A newer report landed, so this review is superseded.
              fresh.href = data.latest_review_url;
              reveal(fresh);
            }
          });
        });
      }

      // Cards carry their own endpoint and never ask for a GitHub check.
      once('ood-review-status', '[data-review-status-url]', context).forEach((card) => {
        startPoller(card.dataset.reviewStatusUrl, (data) => {
          const step = card.querySelector('[data-review-step]');
          if (step && data.step_label) {
            step.textContent = data.step_label;
          }
          const link = card.querySelector('[data-review-link]');
          if (link && data.review_url) {
            link.href = data.review_url;
            reveal(link);
          }
          if (!data.in_flight) {
            reveal(card.querySelector('[data-review-run]'));
          }
        });
      });
    },
  };

})(Drupal, drupalSettings, once);
