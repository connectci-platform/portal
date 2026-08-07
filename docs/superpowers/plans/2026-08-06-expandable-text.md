# Expandable Text ("read more") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a reusable, theme-independent `expandable_text` component that clamps a block of rendered HTML to N lines with a More/Less toggle, and apply it to two consumers: the resource-documentation intro (`field_rp_description`) and the community-persona bio (replacing its hand-rolled char-cut hack).

**Architecture:** A new standalone in-repo Drupal module `expandable_text` provides a `#theme` hook + Twig template + JS/CSS library, reachable two ways — a Twig function `expandable_text(content, lines)` for template consumers, and the `#theme` hook directly for controller/render-array consumers. The JS clamps via **Range-rect line measurement** (walk text nodes, collect distinct rendered line rects, set collapsed height to the bottom of the Nth line rect) — no CSS `line-clamp`, no line-height arithmetic. The resource intro (asp-theme template, pinned repo) uses the Twig function; the persona bio (cssn controller, pinned `access` repo) uses the `#theme` hook via `#context` in its existing `inline_template`.

**Tech Stack:** Drupal 10.6 / PHP 8.3, Twig, `Drupal.behaviors` + `once()`, `@tailwindcss/typography` `prose` styling on the consumers, Cypress (headless Chrome) for e2e. Three repos: in-repo `web/modules/custom/expandable_text` (directly editable), pinned `connectci/asp-theme`, pinned `connectci/access`.

## Global Constraints

- **Do not commit** on the developer's behalf beyond what each task's commit step states; never `git commit` in the pinned repos without the cross-repo push/re-pin/rebuild sequence in Task 8. (Project rule: leave git work to developers — but this plan's per-task commits are the explicit exception, staged for developer review.)
- **No Co-Authored-By / AI-attribution trailers** on any commit message.
- Default collapse target is **4 lines** (`lines = 4`).
- **Never hardcode a pixel value** as a JS constant, threshold, or fallback — the JS must always live-measure. Spike numbers (146px etc.) characterize an approximated stylesheet, not production.
- Component module name is **`expandable_text`**; library id **`expandable_text/expandable_text`**; theme hook **`expandable_text`**; Twig function **`expandable_text(content, lines = 4)`**; CSS fade var **`--expandable-fade-color`** (default `#fff`).
- Button labels are translatable via `Drupal.t('More')` / `Drupal.t('Less')`.
- Full content is always in the DOM; collapse is visual only; when collapsed the content region is `inert`; the toggle is a real `<button>` with `aria-expanded` + `aria-controls`.
- Component must degrade to full text with JS disabled (no CSS-only clamp).
- The two pinned-repo consumers reference the in-repo component across a composer pin; this resolves at runtime because all of `web/modules/custom/` is discovered uniformly. `cssn` must declare `expandable_text` as a dependency so module-enable ordering is enforced.
- **Deploy ordering:** `expandable_text` must be enabled before or with any deploy of the `access` pin that renders the bio, else `{% theme expandable_text %}` fails with "Theme hook not found".

---

## File Structure

**New — in-repo module `web/modules/custom/expandable_text/`:**
- `expandable_text.info.yml` — module definition.
- `expandable_text.module` — `hook_theme()` registering the `expandable_text` theme hook.
- `expandable_text.libraries.yml` — the `expandable_text` library (JS + CSS).
- `expandable_text.services.yml` — registers the TwigExtension service.
- `src/TwigExtension.php` — provides the `expandable_text(content, lines)` Twig function.
- `templates/expandable-text.html.twig` — wrapper markup.
- `js/expandable-text.js` — `Drupal.behaviors.expandableText` (measure + toggle).
- `css/expandable-text.css` — collapsed-state overflow + fade.
- `README.md` — usage (both entry points), for eventual publication.

**Modified — pinned repos:**
- `web/themes/contrib/asp-theme/templates/content/node--access-active-resources-from-cid.html.twig:83-88` — wrap the description in the Twig function.
- `web/modules/custom/access/modules/cssn/src/Controller/CommunityPersonaController.php` — rewrite `userBio()`, build `$bio_expandable`, update both `inline_template` `#context` arrays and `#template` strings (~526, ~805).
- `web/modules/custom/access/modules/cssn/js/cssn.js:1-47` — delete dead `bioMore`/`bioLess`/`setTimeout`.
- `web/modules/custom/access/modules/cssn/cssn.info.yml` — add `expandable_text` dependency.

**Modified — fixtures & tests (in-repo):**
- `web/modules/custom/amp_dev/amp_dev.install` — extend Alpha's `field_rp_description` (~line 918), Pecan's bio (uid 201, ~line 162), and the admin test user's bio (`amp_dev_install_create_test_users()`) to long multi-block.
- `tests/cypress/cypress/e2e/accessmatch2/rp-docs/resource-page.cy.js` — resource-intro assertions (new describe block).
- `tests/cypress/cypress/e2e/accessmatch2/community-persona/bio-expandable.cy.js` — bio assertions (new spec).

---

## Task 1: Scaffold the `expandable_text` module (theme hook + library wiring)

**Files:**
- Create: `web/modules/custom/expandable_text/expandable_text.info.yml`
- Create: `web/modules/custom/expandable_text/expandable_text.module`
- Create: `web/modules/custom/expandable_text/expandable_text.libraries.yml`
- Create: `web/modules/custom/expandable_text/templates/expandable-text.html.twig`
- Create: `web/modules/custom/expandable_text/js/expandable-text.js` (stub)
- Create: `web/modules/custom/expandable_text/css/expandable-text.css` (stub)

**Interfaces:**
- Produces: theme hook `expandable_text` with variables `content` (render array/markup) and `lines` (int, default 4); library `expandable_text/expandable_text`; template variable names `content`, `lines`.

- [ ] **Step 1: Create the module info file**

`web/modules/custom/expandable_text/expandable_text.info.yml`:
```yaml
name: 'Expandable Text'
type: module
description: 'Clamp a block of rendered HTML to N lines with a More/Less toggle. Theme-independent; usable from a Twig template or a render array.'
core_version_requirement: ^10 || ^11
package: Custom
```

- [ ] **Step 2: Register the theme hook**

`web/modules/custom/expandable_text/expandable_text.module`:
```php
<?php

/**
 * @file
 * Expandable Text module: a reusable clamp + More/Less component.
 */

/**
 * Implements hook_theme().
 */
function expandable_text_theme() {
  return [
    'expandable_text' => [
      'variables' => [
        'content' => NULL,
        'lines' => 4,
      ],
      'template' => 'expandable-text',
    ],
  ];
}

/**
 * Implements template_preprocess_HOOK() for expandable_text.
 *
 * Attaches the library on EVERY render of the theme hook, regardless of
 * whether the consumer reached it via the Twig function or a direct #theme
 * render array. This is what makes the library impossible to drop.
 */
function expandable_text_preprocess_expandable_text(array &$variables) {
  $variables['#attached']['library'][] = 'expandable_text/expandable_text';
}
```

- [ ] **Step 3: Declare the library**

`web/modules/custom/expandable_text/expandable_text.libraries.yml`:
```yaml
expandable_text:
  version: 1.0.0
  css:
    component:
      css/expandable-text.css: {}
  js:
    js/expandable-text.js: {}
  dependencies:
    - core/drupal
    - core/once
```

- [ ] **Step 4: Create the template**

`web/modules/custom/expandable_text/templates/expandable-text.html.twig`:
```twig
{#
/**
 * @file
 * Expandable text wrapper. The toggle button is added by JS only when the
 * content actually overflows {{ lines }} rendered lines.
 *
 * Available variables:
 * - content: the already-rendered body (render array or safe markup).
 * - lines: collapse target in rendered lines.
 */
#}
<div class="expandable-text" data-lines="{{ lines }}">
  <div class="expandable-text__content" id="expandable-text-content-{{ random() }}">
    {{ content }}
  </div>
</div>
```

- [ ] **Step 5: Create JS + CSS stubs (real impl in Tasks 3-4)**

`web/modules/custom/expandable_text/js/expandable-text.js`:
```javascript
(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.expandableText = {
    attach: function (context) {
      once('expandable-text', '.expandable-text', context);
    }
  };
})(Drupal, once);
```

`web/modules/custom/expandable_text/css/expandable-text.css`:
```css
/* Real styles land in Task 4. */
.expandable-text__content {
  overflow: hidden;
}
```

- [ ] **Step 6: Enable the module and verify it installs**

Run:
```bash
ddev drush en expandable_text -y && ddev drush pm:list --status=enabled --type=module | grep expandable_text
```
Expected: `Expandable Text (expandable_text)  Enabled`

- [ ] **Step 7: Verify the theme hook renders**

Run:
```bash
ddev drush php:eval "print \Drupal::service('renderer')->renderPlain(['#theme' => 'expandable_text', '#content' => ['#markup' => '<p>hi</p>'], '#lines' => 4]);"
```
Expected: HTML containing `class="expandable-text" data-lines="4"` and `<p>hi</p>`.

- [ ] **Step 8: Commit**

```bash
git add web/modules/custom/expandable_text
git commit -m "feat(expandable_text): scaffold module, theme hook, library"
```

---

## Task 2: TwigExtension providing `expandable_text()` function

**Files:**
- Create: `web/modules/custom/expandable_text/expandable_text.services.yml`
- Create: `web/modules/custom/expandable_text/src/TwigExtension.php`
- Test: `web/modules/custom/expandable_text/tests/src/Kernel/ExpandableTextTwigTest.php`

**Interfaces:**
- Consumes: theme hook `expandable_text` (Task 1).
- Produces: Twig function `expandable_text(content, lines = 4)` returning the render array `['#theme' => 'expandable_text', '#content' => $content, '#lines' => $lines]`. The library is NOT attached here — `expandable_text_preprocess_expandable_text()` (Task 1) attaches it on every render of the theme hook, so both entry points (Twig function and direct `#theme`) get it. The bio consumer (Task 8) therefore does not need `#attached` on its own render array.

- [ ] **Step 1: Write the failing kernel test**

`web/modules/custom/expandable_text/tests/src/Kernel/ExpandableTextTwigTest.php`:
```php
<?php

namespace Drupal\Tests\expandable_text\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * @group expandable_text
 */
class ExpandableTextTwigTest extends KernelTestBase {

  protected static $modules = ['system', 'expandable_text'];

  public function testTwigFunctionRendersComponent(): void {
    $build = [
      '#type' => 'inline_template',
      '#template' => '{{ expandable_text(content, 3) }}',
      '#context' => ['content' => ['#markup' => '<p>alpha</p>']],
    ];
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('class="expandable-text"', $html);
    $this->assertStringContainsString('data-lines="3"', $html);
    $this->assertStringContainsString('<p>alpha</p>', $html);
  }

  public function testThemeHookAttachesLibraryViaPreprocess(): void {
    // The library must attach for BOTH entry points; assert on the #theme
    // render array directly (the bio consumer's path), which exercises the
    // preprocess-based attach rather than the Twig function.
    $build = [
      '#theme' => 'expandable_text',
      '#content' => ['#markup' => 'x'],
      '#lines' => 4,
    ];
    \Drupal::service('renderer')->renderInIsolation($build);
    $this->assertContains(
      'expandable_text/expandable_text',
      $build['#attached']['library'] ?? []
    );
  }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:
```bash
cd web && ../vendor/bin/phpunit -c core modules/custom/expandable_text/tests/src/Kernel/ExpandableTextTwigTest.php
```
Expected: FAIL — `expandable_text` Twig function is unknown / class not found.

- [ ] **Step 3: Register the service**

`web/modules/custom/expandable_text/expandable_text.services.yml`:
```yaml
services:
  expandable_text.twig_extension:
    class: Drupal\expandable_text\TwigExtension
    tags:
      - { name: twig.extension }
```

- [ ] **Step 4: Implement the TwigExtension**

`web/modules/custom/expandable_text/src/TwigExtension.php`:
```php
<?php

namespace Drupal\expandable_text;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides the expandable_text() Twig function.
 */
class TwigExtension extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getFunctions(): array {
    return [
      new TwigFunction('expandable_text', [$this, 'build'], ['is_safe' => ['html']]),
    ];
  }

  /**
   * Builds the expandable_text render array.
   *
   * @param mixed $content
   *   Already-rendered body: a render array or a safe markup string.
   * @param int $lines
   *   Collapse target in rendered lines.
   *
   * @return array
   *   A render array for the expandable_text theme hook.
   */
  public function build($content, int $lines = 4): array {
    // The library is attached by expandable_text_preprocess_expandable_text()
    // on every render of the theme hook, so it is NOT added here — one source
    // of truth, and consumers reaching the hook directly get it too.
    return [
      '#theme' => 'expandable_text',
      '#content' => is_array($content) ? $content : ['#markup' => $content],
      '#lines' => $lines,
    ];
  }

}
```

- [ ] **Step 5: Run the test to verify it passes**

Run:
```bash
cd web && ../vendor/bin/phpunit -c core modules/custom/expandable_text/tests/src/Kernel/ExpandableTextTwigTest.php
```
Expected: PASS (2 tests).

- [ ] **Step 6: Clear cache so the Twig function is live for later manual checks**

Run: `ddev drush cr`
Expected: `Cache rebuild complete.`

- [ ] **Step 7: Commit**

```bash
git add web/modules/custom/expandable_text/expandable_text.services.yml web/modules/custom/expandable_text/src web/modules/custom/expandable_text/tests
git commit -m "feat(expandable_text): add expandable_text() Twig function"
```

---

## Task 3: JS — Range-rect measurement + toggle behavior

**Files:**
- Modify: `web/modules/custom/expandable_text/js/expandable-text.js`

**Interfaces:**
- Consumes: `.expandable-text` wrapper with `data-lines` and a `.expandable-text__content` child carrying a unique id (Task 1 template).
- Produces: on overflow, sets collapsed `max-height` + `is-collapsed` class on `.expandable-text`, injects a `.expandable-text__toggle` `<button>`, and toggles `is-collapsed` / `inert` on click.

This task has no unit test (DOM line-measurement is not meaningfully unit-testable headless without a real layout; it is covered by the Cypress behavior tests in Tasks 9-10). Verify manually in the browser per Step 3.

- [ ] **Step 1: Implement the behavior**

Replace the entire contents of `web/modules/custom/expandable_text/js/expandable-text.js`:
```javascript
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
    collapse(wrapper, content, result.height);
  }

  Drupal.behaviors.expandableText = {
    attach: function (context) {
      var wrappers = once('expandable-text', '.expandable-text', context);
      wrappers.forEach(function (wrapper) {
        apply(wrapper);
        // Re-measure after fonts load (line rects shift on font swap).
        if (document.fonts && document.fonts.ready) {
          document.fonts.ready.then(function () {
            if (!wrapper.classList.contains('is-collapsed') &&
                !wrapper.querySelector('.expandable-text__toggle')) {
              apply(wrapper);
            }
          });
        }
      });

      // Debounced resize re-measure; skip expanded wrappers so a resize
      // while reading does not snap them shut.
      if (!Drupal.behaviors.expandableText._resizeBound) {
        Drupal.behaviors.expandableText._resizeBound = true;
        var timer = null;
        window.addEventListener('resize', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            document.querySelectorAll('.expandable-text.is-collapsed').forEach(function (wrapper) {
              var content = wrapper.querySelector('.expandable-text__content');
              var n = parseInt(wrapper.getAttribute('data-lines'), 10) || 4;
              var r = measure(content, n);
              if (r.overflow) {
                content.style.maxHeight = r.height + 'px';
              }
            });
          }, 150);
        });
      }
    }
  };
})(Drupal, once);
```

- [ ] **Step 2: Clear cache**

Run: `ddev drush cr`
Expected: `Cache rebuild complete.`

- [ ] **Step 3: Manually verify in the browser (temporary test page)**

Create a throwaway node/page, or use the resource page after Task 6, whose body has an `<h3>` + several `<p>` + a `<ul>` exceeding 4 lines. Confirm: the block collapses to ~4 lines, a **More** button appears, clicking it expands and flips to **Less**, clicking again re-collapses; a short block shows no button. (This is the real-page visual verification the spec requires — do not rely on the spike.)

- [ ] **Step 4: Commit**

```bash
git add web/modules/custom/expandable_text/js/expandable-text.js
git commit -m "feat(expandable_text): Range-rect clamp + More/Less toggle"
```

---

## Task 4: CSS — collapsed overflow + fade

**Files:**
- Modify: `web/modules/custom/expandable_text/css/expandable-text.css`

**Interfaces:**
- Consumes: `.expandable-text`, `.expandable-text__content`, `.expandable-text.is-collapsed`, `.expandable-text__toggle` (Tasks 1, 3).

- [ ] **Step 1: Implement the styles**

Replace the entire contents of `web/modules/custom/expandable_text/css/expandable-text.css`:
```css
.expandable-text__content {
  overflow: hidden;
}

/* Fade over the last visible line while collapsed. Fades to the page
   background; override --expandable-fade-color on non-white backgrounds. */
.expandable-text.is-collapsed .expandable-text__content {
  position: relative;
}

.expandable-text.is-collapsed .expandable-text__content::after {
  content: "";
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 1.75rem;
  pointer-events: none;
  background: linear-gradient(
    to bottom,
    rgba(255, 255, 255, 0),
    var(--expandable-fade-color, #fff)
  );
}

.expandable-text__toggle {
  margin-top: 0.5rem;
  border: 0;
  background: transparent;
  padding: 0.25rem 0;
  font-weight: 700;
  color: inherit;
  cursor: pointer;
}
```

- [ ] **Step 2: Clear cache**

Run: `ddev drush cr`
Expected: `Cache rebuild complete.`

- [ ] **Step 3: Verify the fade renders**

Reload the test page from Task 3 Step 3. Confirm the collapsed block shows a bottom fade over the 4th line and the More button is styled (no Bootstrap `btn` classes needed).

- [ ] **Step 4: Commit**

```bash
git add web/modules/custom/expandable_text/css/expandable-text.css
git commit -m "feat(expandable_text): collapsed-state overflow + fade CSS"
```

---

## Task 5: README (for eventual publication)

**Files:**
- Create: `web/modules/custom/expandable_text/README.md`

- [ ] **Step 1: Write the README**

`web/modules/custom/expandable_text/README.md`:
```markdown
# Expandable Text

Clamp a block of already-rendered HTML to N lines with a **More/Less** toggle
that expands it in place. Theme-independent; measures real rendered line
positions (no CSS `line-clamp`, no line-height math), so it clamps cleanly
across headings, paragraphs, and lists.

## Usage

### From a Twig template

    {{ expandable_text(node.field_body.processed, 4) }}

### From a render array (controller / preprocess)

    $build['intro'] = [
      '#theme' => 'expandable_text',
      '#content' => ['#markup' => $rendered_html],
      '#lines' => 4,
    ];

`content` may be a render array or a safe markup string. `lines` defaults to 4.
The JS/CSS library is attached automatically.

## How it works

On attach, the behavior walks the content's text nodes with `Range` and
collects the distinct rendered line rectangles. If the content exceeds `lines`
rendered lines, it sets a collapsed `max-height` at the bottom of the Nth line,
marks the content `inert`, and injects an accessible toggle button. Full
content is always in the DOM; the collapse is visual only and degrades to full
text when JS is disabled.

## Styling

Override `--expandable-fade-color` to match a non-white background:

    .my-region .expandable-text { --expandable-fade-color: #f5f5f5; }
```

- [ ] **Step 2: Commit**

```bash
git add web/modules/custom/expandable_text/README.md
git commit -m "docs(expandable_text): usage README"
```

---

## Task 6: Resource-intro consumer (asp-theme template)

**Files:**
- Modify: `web/themes/contrib/asp-theme/templates/content/node--access-active-resources-from-cid.html.twig:83-88`

**Interfaces:**
- Consumes: Twig function `expandable_text(content, lines)` (Task 2).

> **Cross-repo:** this file is in the pinned `connectci/asp-theme` repo. Editing it directly in `web/themes/contrib/asp-theme` is fine for local verification; landing it requires the push/re-pin/rebuild sequence in Task 8. Commit here is a local checkpoint.

- [ ] **Step 1: Wrap the description in the Twig function**

In `web/themes/contrib/asp-theme/templates/content/node--access-active-resources-from-cid.html.twig`, replace lines 83-88:
```twig
      {# --- Description --- #}
      {% if rp_description %}
        <div class="rp-description prose max-w-none mb-8">
          {{ node.field_rp_description.processed }}
        </div>
      {% endif %}
```
with:
```twig
      {# --- Description --- #}
      {% if rp_description %}
        <div class="rp-description prose max-w-none mb-8">
          {{ expandable_text(node.field_rp_description.processed, 4) }}
        </div>
      {% endif %}
```

- [ ] **Step 2: Clear cache**

Run: `ddev drush cr`
Expected: `Cache rebuild complete.`

- [ ] **Step 3: Verify on a real resource page**

Set the domain and open a resource doc page with a long description:
```bash
ddev drush uli
```
Navigate to a `/documentation/resources/{slug}` whose `field_rp_description` is long and multi-block. Confirm the intro clamps to ~4 lines with a working More/Less toggle, and a short-description resource shows no toggle. (If no long fixture exists yet, verify after Task 7.)

- [ ] **Step 4: Commit (local checkpoint — see Task 8 for landing)**

```bash
git -C web/themes/contrib/asp-theme add templates/content/node--access-active-resources-from-cid.html.twig
git -C web/themes/contrib/asp-theme commit -m "feat: expandable read-more on resource intro"
```

---

## Task 7: Fixtures — make Alpha's description, Pecan's bio, and the admin's bio long + multi-block

**Files:**
- Modify: `web/modules/custom/amp_dev/amp_dev.install:~918` (Test Resource Alpha `field_rp_description`)
- Modify: `web/modules/custom/amp_dev/amp_dev.install:~162` (Pecan Pie, uid 201, `bio`)
- Modify: `web/modules/custom/amp_dev/amp_dev.install` `amp_dev_install_create_test_users()` (~line 365) — give `administrator_test_user` a long bio

**Interfaces:**
- Produces: `Test Resource Alpha` (`/documentation/resources/alpha`) with a heading-led, multi-block `field_rp_description` > 4 rendered lines; Pecan Pie (uid 201, public persona `/community-persona/201`) with a multi-block `field_user_bio`; `administrator_test_user` (login `administrator@amptesting.com` / `b8QW]X9h7#5n`) with a multi-block bio so the logged-in own-persona test has a working login (Task 10). All three are existing fixtures — extend, don't add.

> Rationale for the admin bio: **Pecan's `name` is never applied** — `amp_dev.install:230` (`$user->set('name', ...)`) is commented out, so uid 201's username is NOT `test_pecan_pie` (a prior engineer's comment in `hide-profile.cy.js:37-40` warns of exactly this, and `drush sql-sanitize` never rewrites `name`). So no test can reliably `loginAs` Pecan. The public-persona page `/community-persona/201` needs no login and covers the `communityPersonaPublic()` build + the component. For the own-persona (`communityPersona()`) build, log in as the **admin fixture every other spec uses** (`administrator@amptesting.com`), which is created bare with no bio — so give it a long bio here.

- [ ] **Step 1: Lengthen Alpha's description to heading-led multi-block**

In `amp_dev.install`, replace the `Test Resource Alpha` `field_rp_description` array (~line 918) — **preserving the existing `format` (`full_html`) and `summary` keys**:
```php
    'field_rp_description' => [
      'value' => '<h3>Overview</h3><p>Test Resource Alpha is a powerful GPU-accelerated supercomputer funded by a $10 million NSF grant, designed for a broad range of scientific and engineering workloads from traditional HPC simulation to AI and machine learning research.</p><ul><li>GPU partitions: standard and shared</li><li>Storage tiers: home, scratch, and project</li></ul><p>Researchers can access Alpha through standard HPC interfaces including Open OnDemand (OOD) and JupyterHub, enabling both command-line and browser-based workflows.</p><p>Consult the scheduler notes and queue limits before submitting large jobs, and review the software module list for available toolchains.</p>',
      'summary' => 'GPU-accelerated supercomputer for HPC, AI, and machine learning workloads.',
      'format' => 'full_html',
    ],
```
(The existing fixture uses `'format' => 'full_html'` — keep it. Both `full_html` and the field's configured `resource_docs` permit `<h3>`/`<ul>`, so rendering is identical for this test.) The trailing `Consult the scheduler notes` sentence is the clipped-while-collapsed marker the Cypress test asserts on.

- [ ] **Step 2: Lengthen Pecan's bio to multi-block**

In `amp_dev.install`, replace Pecan's `'bio' => 'I am a pie',` (~line 162) with a multi-block value > 4 rendered lines whose last paragraph mentions `mentor students` (the Cypress clipped-text marker):
```php
      'bio' => '<p>I am a research computing facilitator supporting HPC users across several campuses. My work spans allocations, onboarding, and training for new PI groups.</p><p>Before this role I administered a mid-size cluster and maintained the scientific software stack. I still contribute documentation and the occasional module file.</p><p>Outside of work I mentor students getting started with parallel computing and cloud workflows.</p>',
```
(The bio is applied via `$user->set('field_user_bio', $user_data['bio'])` at ~line 229; no format is passed, so `userBio()`'s `->format ?: 'basic_html'` fallback governs.) Leave the other pie users' short `'I am a pie'` bios unchanged — they serve as the short-bio / no-toggle case.

- [ ] **Step 3: Give the admin test user a long bio**

In `amp_dev_install_create_test_users()` (~line 378), add a `'bio'` key to the `administrator_test_user` entry in the `$test_users` array:
```php
    [
      'name' => 'administrator_test_user',
      'mail' => 'administrator@amptesting.com',
      'pass' => 'b8QW]X9h7#5n',
      'first_name' => 'Administrator',
      'last_name' => 'Testuser',
      'roles' => ['administrator'],
      'alias' => '/user/administratortestuser',
      'bio' => '<p>I administer the ACCESS test environment and coordinate the facilitation team across partner campuses. My work covers onboarding, allocations, and training.</p><p>I maintain the scientific software stack and the documentation that goes with it, and I still write the occasional module file when a gateway needs one.</p><p>Reach me during weekly office hours for help getting started on the cluster.</p>',
    ],
```
Then in the `foreach ($test_users as $data)` loop, before the existing `$user->save();` (~line 412), add a conditional set (the `authenticated_test_user` entry has no `bio` key, so guard it):
```php
    if (!empty($data['bio'])) {
      $user->set('field_user_bio', $data['bio']);
    }
```
The last paragraph mentions `office hours` — the clipped-text marker the Cypress own-persona test asserts on.

- [ ] **Step 4: Reinstall the fixture module and verify content**

Run:
```bash
ddev drush pmu amp_dev -y && ddev drush en amp_dev -y && ddev drush cr
ddev drush php:eval "\$n = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Test Resource Alpha']); \$n = reset(\$n); echo strpos(\$n->get('field_rp_description')->value, '<h3>') !== FALSE ? 'RESOURCE OK' : 'MISSING';"
ddev drush php:eval "\$u = \Drupal\user\Entity\User::load(201); echo strpos(\$u->get('field_user_bio')->value, '<p>') !== FALSE ? 'PECAN BIO OK' : 'MISSING';"
ddev drush php:eval "\$a = user_load_by_mail('administrator@amptesting.com'); echo (\$a && strpos(\$a->get('field_user_bio')->value, '<p>') !== FALSE) ? 'ADMIN BIO OK' : 'MISSING';"
```
Expected: `RESOURCE OK`, `PECAN BIO OK`, `ADMIN BIO OK`.

- [ ] **Step 5: Commit**

```bash
git add web/modules/custom/amp_dev/amp_dev.install
git commit -m "test(amp_dev): long multi-block Alpha description + Pecan and admin bios for expandable text"
```

---

## Task 8: Bio consumer (cssn controller + dead-code removal + dependency)

**Files:**
- Modify: `web/modules/custom/access/modules/cssn/src/Controller/CommunityPersonaController.php` (`userBio()` ~393; own-persona build ~525-688; other-user build ~804-947)
- Modify: `web/modules/custom/access/modules/cssn/js/cssn.js:1-47`
- Modify: `web/modules/custom/access/modules/cssn/cssn.info.yml`

**Interfaces:**
- Consumes: theme hook `expandable_text` (Task 1).
- Produces: `userBio($uid)` now returns a single string (the processed bio, `''` when empty); both persona builds pass a `bio_expandable` render array in `#context`.

> **Cross-repo:** all three files are in the pinned `connectci/access` repo. Edit directly in `web/modules/custom/access` for local verification; landing requires Task 8's own sub-repo commit plus the re-pin/rebuild sequence in Task 11.

- [ ] **Step 1: Rewrite `userBio()` to return one processed string**

In `CommunityPersonaController.php`, replace the body of `userBio()` (lines ~393-423) with:
```php
  public function userBio($uid) {
    $user_entity = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    if (!$user_entity || $user_entity->get('field_user_bio')->isEmpty()) {
      return '';
    }
    // basic_html format; the expandable_text component handles clamping.
    return check_markup(
      $user_entity->get('field_user_bio')->value,
      $user_entity->get('field_user_bio')->format ?: 'basic_html'
    );
  }
```

- [ ] **Step 2: Update the own-persona caller (~line 456)**

Replace lines ~455-458:
```php
    // User Bio.
    $user_bio = $this->userBio($current_user->id());
    $bio_summary = $user_bio[0];
    $bio = $user_bio[1];
```
with:
```php
    // User Bio.
    $bio = $this->userBio($current_user->id());
    $bio_expandable = $bio === '' ? '' : [
      '#theme' => 'expandable_text',
      '#content' => ['#markup' => $bio],
      '#lines' => 4,
    ];
```

- [ ] **Step 3: Update the own-persona template + context**

In the same build's `#template` (~lines 541-548), replace:
```twig
            <div class="d-flex flex flex-wrap py-3">
              <div id="bio-summary" aria-hidden="false">
                {{ bio_summary |raw }}
              </div>
              <div id="full-bio" class="sr-only" aria-hidden="true">
                {{ bio |raw }}
              </div>
            </div>
```
with:
```twig
            <div class="d-flex flex flex-wrap py-3">
              {{ bio_expandable }}
            </div>
```
Then in that build's `#context` (~lines 686-688), replace:
```php
        'bio_summary' => $bio_summary,
        'bio' => $bio,
```
with:
```php
        'bio' => $bio,
        'bio_expandable' => $bio_expandable,
```
(Keep `'bio' => $bio` — the `{% if bio %}` gate and `{% set skill_margin = "my-3" %}` still key on it. `bio` is `''` when empty, so the gate behaves exactly as before.)

- [ ] **Step 4: Update the other-user caller (~line 775)**

Replace lines ~774-777:
```php
      // User Bio.
      $user_bio = $this->userBio($user->id());
      $bio_summary = $user_bio[0];
      $bio = $user_bio[1];
```
with:
```php
      // User Bio.
      $bio = $this->userBio($user->id());
      $bio_expandable = $bio === '' ? '' : [
        '#theme' => 'expandable_text',
        '#content' => ['#markup' => $bio],
        '#lines' => 4,
      ];
```

- [ ] **Step 5: Update the other-user template + context**

In the other-user build's `#template` (~lines 820-827), replace:
```twig
              <div class="d-flex flex flex-wrap py-3">
                <div id="bio-summary" aria-hidden="false">
                  {{ bio_summary |raw }}
                </div>
                <div id="full-bio" class="sr-only" aria-hidden="true">
                  {{ bio |raw }}
                </div>
              </div>
```
with:
```twig
              <div class="d-flex flex flex-wrap py-3">
                {{ bio_expandable }}
              </div>
```
Then in that build's `#context` (~line 949, the `bio_summary`/`bio` entries), replace:
```php
          'bio_summary' => $bio_summary,
          'bio' => $bio,
```
with:
```php
          'bio' => $bio,
          'bio_expandable' => $bio_expandable,
```

- [ ] **Step 6: Delete the dead JS**

In `web/modules/custom/access/modules/cssn/js/cssn.js`, delete lines 1-47 (the `bioMore()`, `bioLess()`, and `setTimeout(...)` block) in their entirety. If the file is now empty, leave it as an empty file (do NOT remove the `js` key from `cssn.libraries.yml` — the `cssn_library`'s `css/cssn.css` is still needed for `.appverse-contribs`, and the library referencing an empty JS file is harmless). If `cssn-directory.js` or other JS remains referenced by other libraries, leave those untouched.

- [ ] **Step 7: Add the module dependency**

In `web/modules/custom/access/modules/cssn/cssn.info.yml`, change:
```yaml
dependencies:
  - access
```
to:
```yaml
dependencies:
  - access
  - expandable_text
```

- [ ] **Step 8: Clear cache and verify both persona pages render the component**

Run: `ddev drush cr`
Then get a login link and view your own community persona and another user's profile:
```bash
ddev drush uli
```
Confirm on **both** pages: the bio renders inside `.expandable-text`, clamps to ~4 lines for the long-bio fixture user with a working More/Less toggle, the old `#bio-summary`/`#full-bio`/`bio-more` ids are gone, and a short-bio user shows no toggle. Verify no JS console errors (the dead `bioMore`/`bioLess` are gone; nothing should reference them).

- [ ] **Step 9: Commit (in the access sub-repo — local checkpoint)**

```bash
git -C web/modules/custom/access add modules/cssn/src/Controller/CommunityPersonaController.php modules/cssn/js/cssn.js modules/cssn/cssn.info.yml
git -C web/modules/custom/access commit -m "feat(cssn): use expandable_text component for persona bio; remove dead read-more JS"
```

---

## Task 9: Cypress — resource-intro behavior

**Files:**
- Modify: `tests/cypress/cypress/e2e/accessmatch2/rp-docs/resource-page.cy.js`

> Run with `CYPRESS_BASE_URL=https://accessmatch.ddev.site` (the accessmatch2 suite's domain). Assertions target DOM state, not pixel heights (headless font-loading makes height assertions flaky). This spec already has three describe blocks — Alpha (full), Beta (sparse), Gamma. Add a new describe for the expandable behavior; Alpha (extended in Task 7) is the long-intro case, Beta is the short-intro/no-toggle case.

- [ ] **Step 1: Write the failing tests**

Append a new describe block to `tests/cypress/cypress/e2e/accessmatch2/rp-docs/resource-page.cy.js`:
```javascript
describe("Resource Documentation Page — expandable intro", () => {

  it("clamps Alpha's long multi-block intro with a working toggle", () => {
    cy.visit("/documentation/resources/alpha");
    cy.get(".rp-description .expandable-text").should("exist");
    cy.get(".rp-description .expandable-text.is-collapsed").should("exist");
    cy.get(".rp-description .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");
    // The trailing paragraph is clipped while collapsed.
    cy.contains(".rp-description", "Consult the scheduler notes").should("not.be.visible");
    cy.get(".rp-description .expandable-text__toggle").click();
    cy.get(".rp-description .expandable-text").should("not.have.class", "is-collapsed");
    cy.get(".rp-description .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "true")
      .and("contain.text", "Less");
    cy.contains(".rp-description", "Consult the scheduler notes").should("be.visible");
  });

  it("shows no toggle for Beta's short intro", () => {
    cy.visit("/documentation/resources/beta");
    // Beta's description is a single short paragraph (< 4 lines).
    cy.get(".rp-description .expandable-text__toggle").should("not.exist");
  });

});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:
```bash
CYPRESS_BASE_URL=https://accessmatch.ddev.site vendor/bin/robo cypress accessmatch2
```
Expected: FAIL — the `.expandable-text` structure is absent until the asp-theme edit (Task 6) is live locally.

- [ ] **Step 3: Ensure Task 6 + Task 7 are applied locally, then run to pass**

Run:
```bash
ddev drush cr
CYPRESS_BASE_URL=https://accessmatch.ddev.site vendor/bin/robo cypress accessmatch2
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/cypress/cypress/e2e/accessmatch2/rp-docs/resource-page.cy.js
git commit -m "test(cypress): resource intro expandable read-more"
```

---

## Task 10: Cypress — persona bio behavior

**Files:**
- Create: `tests/cypress/cypress/e2e/accessmatch2/community-persona/bio-expandable.cy.js`

> The community-persona suite lives at `tests/cypress/cypress/e2e/accessmatch2/community-persona/` (e.g. `hide-profile.cy.js`); run with `CYPRESS_BASE_URL=https://accessmatch.ddev.site`.
>
> **Two builds, two login realities:**
> - The `communityPersonaPublic()` build serves `/community-persona/{uid}` with **no login** — use Pecan (uid 201, given a long bio in Task 7) at `/community-persona/201`. Do NOT try to `loginAs` Pecan: her `name` is never set in the fixture (`amp_dev.install:230` is commented out), so `test_pecan_pie` is not her real username, and every existing spec avoids logging in as her for that reason.
> - The `communityPersona()` build serves the logged-in user's OWN `/community-persona` — log in as the **admin fixture every other spec uses**, `administrator@amptesting.com` / `b8QW]X9h7#5n` (given a long bio in Task 7). Its clipped-text marker is `office hours`.

- [ ] **Step 1: Write the failing tests**

`tests/cypress/cypress/e2e/accessmatch2/community-persona/bio-expandable.cy.js`:
```javascript
/*
  Expandable bio on the community persona (D8-2796).
  Pecan Pie (uid 201) is seeded with a long multi-block bio -> her public
  profile clamps the bio with a More/Less toggle. The admin test user is
  seeded with a long bio -> the own-persona page clamps too. The old
  #bio-summary / #full-bio markup is gone.

  NB: Pecan's username is not set in the fixture (amp_dev.install:230 is
  commented out), so we never loginAs Pecan — only her public /community-
  persona/201 page, which needs no login. The own-persona case logs in as
  the admin fixture that every other spec in this suite uses.
*/

const PECAN_UID = 201;
const ADMIN_USER = "administrator@amptesting.com";
const ADMIN_PASS = "b8QW]X9h7#5n";

describe("Community persona — expandable bio (public profile)", () => {

  it("clamps Pecan's long bio with a working toggle", () => {
    cy.visit(`/community-persona/${PECAN_UID}`);
    cy.get("#community-persona .expandable-text").should("exist");
    cy.get("#community-persona .expandable-text.is-collapsed").should("exist");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");
    cy.contains("#community-persona", "mentor students").should("not.be.visible");
    cy.get("#community-persona .expandable-text__toggle").click();
    cy.contains("#community-persona", "mentor students").should("be.visible");
    // Old hand-rolled markup is gone.
    cy.get("#community-persona #bio-summary").should("not.exist");
    cy.get("#community-persona #full-bio").should("not.exist");
  });

});

describe("Community persona — expandable bio (own persona)", () => {

  it("clamps the admin's long bio on their own persona page", () => {
    cy.loginAs(ADMIN_USER, ADMIN_PASS);
    cy.visit("/community-persona");
    cy.get("#community-persona .expandable-text").should("exist");
    cy.get("#community-persona .expandable-text.is-collapsed").should("exist");
    cy.get("#community-persona .expandable-text__toggle")
      .should("have.attr", "aria-expanded", "false")
      .and("contain.text", "More");
    cy.contains("#community-persona", "office hours").should("not.be.visible");
    cy.get("#community-persona .expandable-text__toggle").click();
    cy.contains("#community-persona", "office hours").should("be.visible");
    // Old hand-rolled markup is gone.
    cy.get("#community-persona #bio-summary").should("not.exist");
  });

});
```

- [ ] **Step 2: Run to verify failure**

Run:
```bash
CYPRESS_BASE_URL=https://accessmatch.ddev.site vendor/bin/robo cypress accessmatch2
```
Expected: FAIL until Task 8 is applied locally (the `.expandable-text` structure is absent; the old `#bio-summary` still exists).

- [ ] **Step 3: With Task 8 applied locally, run to pass**

Run:
```bash
ddev drush cr
CYPRESS_BASE_URL=https://accessmatch.ddev.site vendor/bin/robo cypress accessmatch2
```
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/cypress/cypress/e2e/accessmatch2/community-persona/bio-expandable.cy.js
git commit -m "test(cypress): persona bio expandable read-more, old markup gone"
```

---

## Task 11: Land the pinned-repo changes (asp-theme + access) and rebuild the CI artifact

**Files:**
- Modify: root `composer.json` / `composer.lock` (re-pin) — via composer commands, not hand-edited.

> This task carries the cross-repo landing that Tasks 6 and 8 checkpointed locally. It follows the sequencing this codebase requires (verified painful lesson: never rebuild the artifact before the re-pin lands).

- [ ] **Step 1: Push the asp-theme commit to its branch**

```bash
git -C web/themes/contrib/asp-theme push origin HEAD
```
Note the pushed SHA.

- [ ] **Step 2: Push the access commit to its branch**

```bash
git -C web/modules/custom/access push origin HEAD
```
Note the pushed SHA.

- [ ] **Step 3: Re-pin both packages in the main repo**

```bash
composer update connectci/asp-theme connectci/access --with-dependencies
```
Confirm `composer.lock` now references the two SHAs pushed in Steps 1-2.

- [ ] **Step 4: Commit the lock re-pin**

```bash
git add composer.json composer.lock
git commit -m "composer: re-pin asp-theme + access for expandable text"
```

- [ ] **Step 5: Push the main branch, THEN trigger the artifact rebuild**

```bash
git push origin HEAD
gh workflow run backupdb.yml -f database_branch=$(git branch --show-current)
```

- [ ] **Step 6: Confirm the build log shows the expected access + asp-theme SHAs**

Watch the run and verify it installed the re-pinned SHAs (not stale ones):
```bash
gh run watch $(gh run list --workflow=backupdb.yml --limit=1 --json databaseId --jq '.[0].databaseId')
```
Expected: build succeeds and the composer-install step shows the SHAs from Steps 1-2.

- [ ] **Step 7: Verify CI is green on the branch PR**

Confirm the branch's PR CI (which downloads the artifact filtered by `workflow: backupdb.yml` + branch) runs the accessmatch2 suite green, exercising Tasks 9-10 against the rebuilt fixtures.

---

## Notes for the executor

- **Local-first, land last.** Tasks 1-10 are fully verifiable on local ddev without any push. Task 11 is the only cross-repo landing step; do it once everything is green locally. Do not push to the `md` branch to "check" — verify on ddev (project rule).
- **Deploy ordering is a hard constraint** (Global Constraints): the `expandable_text` module must be enabled before/with any `access`-pin deploy. The `cssn` → `expandable_text` dependency (Task 8 Step 7) enforces this under config-import/enable, but note it in the PR/deploy description too.
- **Range measurement, never pixels:** if any reviewer or executor is tempted to add a numeric fallback height, that violates a Global Constraint — the JS must always live-measure.
