/**
 * @file
 * Reveals the institution and Carnegie fields on the Join Campus Champions
 * form when the applicant selects the "Other" organization (node 3695).
 *
 * Webform #states cannot reliably match an entity_autocomplete value, whose
 * rendered value is a label like "Other (3695)" rather than the bare node id,
 * so this mirrors the detection used by user_profiles/organization-toggle.
 */
(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.ccJoinOrgToggle = {
    attach: function (context) {
      once('cc-join-org-toggle', '[name="field_access_organization"]', context).forEach(function (element) {
        var $org = $(element);
        var $institution = $('#edit-user-institution').closest('.js-form-wrapper, fieldset').first();
        var $carnegie = $('[name="carnegie_classification"]').closest('.js-form-item, .form-item').first();

        function isOther() {
          var v = $org.val();
          return !!v && (v === '3695' || v.indexOf('3695') !== -1);
        }

        function toggle() {
          if (isOther()) {
            $institution.show();
            $carnegie.show();
            $carnegie.find('input').prop('required', true);
          }
          else {
            $institution.hide();
            $carnegie.hide();
            $carnegie.find('input').prop('required', false);
          }
        }

        toggle();
        $org.on('change keyup input autocompleteselect autocompletechange', function () {
          setTimeout(toggle, 100);
        });
      });

      // In the "Other" flow, turn the institution name into a type-ahead
      // against the Carnegie institution list. Selecting a suggestion keeps
      // the institution name in the field and fills the Carnegie code from the
      // chosen row. Free text is still allowed for institutions not in the
      // list (international schools, companies); those simply leave the code
      // blank, which is correct.
      once('cc-institution-carnegie', '[name="institution_name"]', context).forEach(function (element) {
        var $institution = $(element);

        // Carnegie is derived from the institution selection in this flow, so
        // any edit to the institution name invalidates a previously filled
        // code. Clear it on every change; a fresh selection refills it. This
        // prevents a stale code from a prior pick sticking to a new, unmatched
        // institution.
        $institution.on('input change', function () {
          $('[name="carnegie_classification"]').val('');
        });

        $institution.autocomplete({
          minLength: 3,
          source: function (request, response) {
            $.getJSON('/autocomplete/carnegiecode', { q: request.term }, function (rows) {
              response((rows || []).map(function (r) {
                return { label: r.label, value: r.label, code: r.value };
              }));
            });
          },
          select: function (event, ui) {
            // Defer so the clear-on-change handler (which jQuery UI may fire
            // while writing the selected value) runs first, then set the code.
            var code = ui.item.code;
            setTimeout(function () {
              $('[name="carnegie_classification"]').val(code);
            }, 0);
          },
        });
      });
    }
  };

})(jQuery, Drupal, once);
