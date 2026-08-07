# Expandable Text ("read more") — Design

**Ticket:** D8-2796 (Resource Documentation intro paragraphs should have "read more")

## Goal

Give long text blocks a "read more" affordance: clamp the content to a small number of lines with a **More/Less** toggle that expands it in place. Build it as a reusable, theme-independent component and apply it to **two consumers in this effort**: (1) the resource documentation intro (`field_rp_description` on `/documentation/resources/{slug}`), and (2) the community-persona bio, replacing its existing hand-rolled "read more" hack. Two real consumers, so the reusable abstraction is proven, not speculative.

## Motivation

The individual resource documentation page renders the full `field_rp_description` with no truncation, so a long intro pushes the actual resource data far down the page. The site already has a "read more" on the community-persona bio, but it is a poor pattern worth replacing rather than copying:

- Character-based truncation at 450 chars — cuts mid-word.
- HTML built as strings inside a controller (`CommunityPersonaController::userBio()`), with a `setTimeout(500)` block in `cssn.js` that duplicates the same button markup against a selector (`.user-profile-view #bio-summary.more`) that matches no live markup — dead code that has to be read to be trusted.
- Inline `onclick="bioMore()"` global functions rather than `Drupal.behaviors`; does not re-init on AJAX.
- Always adds the toggle when the bio exceeds 450 chars, even when the rendered result would not actually overflow.

## Non-goals

- Routing `field_rp_description` (or the bio) through a standard Drupal field-display formatter. Both render by hand — the resource intro via `{{ node.field_rp_description.processed }}` in a Twig template, the bio via an `inline_template` string in a controller — so a field formatter (e.g. `smart_trim`) does not attach without re-architecting those render paths. Rejected: see Alternatives.
- Changing where the bio appears or its 4-line collapse behavior beyond what the component provides; the swap is a like-for-like replacement of the truncation *mechanism*, not a redesign of the persona.

## Alternatives considered

1. **contrib `smart_trim` field formatter.** The standard Drupal answer for trimming a field with a "more" toggle, and it is installable. Rejected because it is a *field-display formatter*: neither target renders through a field display (resource intro is a raw Twig print; bio is a controller `inline_template` string), so it cannot attach without converting both render paths to configured field displays — more invasive and more fragile than the problem warrants.
2. **asp-theme Twig macro + JS + CSS.** Presentation-in-theme is idiomatic, but the site runs multiple active themes (aspTheme, champions, ood, pascience, nect are all enabled; default per-domain), and the bio consumer renders via a module `inline_template` that is theme-independent. A theme-scoped macro would strand the other themes and the bio. Rejected in favor of a theme-independent module element.
3. **Reusable themed component in a custom module (chosen).** A `#theme` hook + template + JS/CSS library, reachable two ways — a Twig function for template consumers and the `#theme` hook for controller/render-array consumers — so any template or controller can wrap content in it. Theme-independent, works from both a template and a controller, no field-display dependency.

## Component design

A reusable component that takes a block of already-rendered content and a line limit, clamps the content, and adds a toggle only when the content actually overflows.

### Component surface

Prefer the lightest machinery that works: a **`#theme` hook + template + attached library**, exposed through a **Twig function** so templates can print it directly. A full custom `#type` render element (needs `hook_element_info` + a `#pre_render`) is not required — the value here is markup + a library, which `hook_theme` provides with less ceremony.

- `expandable_text(content, lines = 4)` (Twig function) returns a render array: `['#theme' => 'expandable_text', '#content' => $content, '#lines' => $lines, '#attached' => ['library' => ['<module>/expandable_text']]]`.
- `#content` — a render array or safe markup string (the already-processed body). The component does not process or trim text server-side.
- `#lines` — integer, collapse target in lines. Default 4.
- The `expandable_text` theme template renders the wrapper markup below. The `#theme` hook + render array is the underlying mechanism, and the component exposes **two entry points into it**: the **Twig function** `expandable_text(content, lines)` for template consumers (the resource intro), and the **`#theme` hook** directly for controller/render-array consumers (the persona bio). Both land on the same template + library; the two consumers deliberately use different entry points, which validates the component's dual surface (see "Application" sections).
- **Twig-function registration:** the function is provided by a `TwigExtension` class implementing `\Twig\Extension\ExtensionInterface` (a `getFunctions()` returning a `\Twig\TwigFunction('expandable_text', ...)`), registered as a `twig.extension`-tagged service in the module's `*.services.yml`. There is no existing `TwigExtension` in the in-repo custom modules to copy, so follow Drupal core's precedent (e.g. `Drupal\Core\Template\TwigExtension`). This is real service-registration ceremony, not a footnote — it is an implementation-plan task in its own right.
- **Caching:** the `#attached` library rides on the node render array and survives standard render/dynamic-page cache and BigPipe by default (only a custom `#cache` override would strip it). Since the collapse is client-side JS over already-rendered content, a dropped library degrades to full text (safe), never wrong content. No special cache contexts/tags needed; flag only if custom cache logic touches these node types.

### Markup

```html
<div class="expandable-text" data-lines="4">
  <div class="expandable-text__content" id="expandable-text-content-{unique}">
    {{ content }}
  </div>
  <!-- toggle button is added by JS only when the content overflows -->
</div>
```

- The full content is always present in the DOM (accessible, indexable). Collapse is purely visual.
- The unique id ties the button's `aria-controls` to the content region.
- `data-lines` carries the collapse target; the element renders unclamped until JS applies the measured height.

### Truncation mechanism (why not CSS line-clamp)

`field_rp_description` is rich HTML from the `resource_docs` CKEditor format — multiple `<p>`, `<ul>`, `<h3>` are permitted and common. This rules out both CSS-only options:

- **`-webkit-line-clamp`** only clamps the inline content of a *single* block box. On multi-paragraph content it does not clamp across children — it clamps the last block while showing earlier blocks in full, so the collapsed state is not an N-line preview. (Documented limitation, verified against the CKEditor format permissions.)
- The standard unprefixed **`line-clamp`** (CSS Overflow Level 4) *does* clamp across multiple blocks, but as of 2026 it is **not Baseline** ("limited availability — does not work in some widely-used browsers" per MDN), so it cannot be the sole mechanism.
- A fixed **`max-height`** clamp works across blocks but cuts at an arbitrary pixel boundary — mid-line, showing partial glyphs.

A **line-height arithmetic** approach (`N × line-height`) is also rejected — and this was verified empirically, not argued. On the real prose-styled content (`<h3>` + `<p>` + `<ul>`), `N × wrapper-line-height` cut ~50px too short (≈2.5 lines instead of 4), and `N × paragraph-line-height` was still ~34px short, because the heading's larger line-height and the inter-block margins consume vertical space no single line-height accounts for. Any scalar-line-height formula is wrong for heterogeneous content.

The correct, verified mechanism is **Range-rect line measurement**: walk the content's text nodes with `document.createRange()`, collect the distinct rendered line rectangles (dedup by rounded `top`), and set the collapsed height to the bottom of the Nth line rectangle (relative to the content box). This measures *where lines actually render*, so it respects headings, lists, and margins and cuts cleanly on a real line boundary — no line-height math. Standard CSS `line-clamp` may replace this once it reaches Baseline; until then the Range measurement is the mechanism.

The mechanism was verified in headless Chrome against a heading-led multi-block intro (target 4 lines → collapsed at the exact bottom of the 4th rendered line; a one-line intro correctly reported no overflow). **Caveat on those numbers:** that spike used a *hand-approximated* `prose` stylesheet (`system-ui` font, hand-picked line-heights), not asp-theme's compiled Tailwind output or its real font stack, so the specific pixel figures characterize the spike, not the production page. What the spike proves is that the *approach* is correct where scalar-line-height formulas failed — and because Range measurement reads whatever rects actually render, it stays correct under a different font stack or a web-font swap. Do **not** hardcode any spike-derived pixel value as a JS constant, threshold, or fallback; the JS must always live-measure. Re-verify visually on the real page during implementation.

### CSS

- `.expandable-text__content` has `overflow: hidden`; its collapsed `max-height` is set by JS to the measured Nth-line bottom (not a CSS constant, not a line-height multiple). A subtle bottom gradient fade over the last visible line softens the cut. The fade fades to the page background color — supplied as a CSS custom property (`--expandable-fade-color`, default `#fff`) so a consumer on a non-white background can override it; the resource page background is white.
- `.expandable-text.is-expanded .expandable-text__content` clears the max-height (shows everything).
- No clamp is applied until JS measures and marks the element, so with JS off the full text shows — never trapped.

### JS behavior

`Drupal.behaviors.expandableText`, using `once()`:

- **measure(el, N)** — walk `el`'s content text nodes via `document.createTreeWalker(SHOW_TEXT)` + `Range`, collect distinct line-top rectangles sorted by `top`. If the number of rendered lines ≤ N, return "no overflow". Otherwise return the Nth line's `bottom` minus the content box `top` = the collapsed height.
- On attach, for each `.expandable-text` read `data-lines` (N, default 4) and run `measure`. Only if it overflows: set the collapsed `max-height`, add the collapsed class, and inject a real `<button class="expandable-text__toggle" type="button" aria-expanded="false" aria-controls="{content-id}">More</button>` (label via `Drupal.t('More')` / `Drupal.t('Less')`). Wire click to toggle `.is-expanded`, swap the label and `aria-expanded`, and manage focus.
- **Keyboard/a11y of hidden overflow:** when collapsed, links in the hidden overflow are visually clipped but still in the DOM and tabbable. Set `inert` (or `tabindex=-1` + `aria-hidden` fallback) on the content region while collapsed so keyboard users can't tab into invisible text; clear it on expand. (Addresses the tab-into-hidden-content a11y bug the "full content always in DOM" choice creates.)
- **Re-measure after `document.fonts.ready`** (a late web-font swap changes where lines render) and on a debounced `window.resize` (reflow changes wrap points). Re-measure **must skip elements currently in `.is-expanded`** so a resize while the user is reading the expanded text does not snap it shut. `document.fonts.ready` is a resolved promise for already-loaded fonts, so `.then()` still fires for AJAX-attached content.
- **Measure-while-hidden guard:** if the element (or an ancestor) is `display:none` at attach, all rects read 0 and the decision is wrong. Guard by checking `el.offsetParent !== null` (or `getClientRects().length`) before measuring; if hidden, defer via an `IntersectionObserver` / re-run when shown.
- Re-runs on AJAX-inserted content via `once()`.

## Application to the resource intro (D8-2796)

In `asp-theme/templates/content/node--access-active-resources-from-cid.html.twig` (currently lines 84–86):

```twig
{% if rp_description %}
  <div class="rp-description prose max-w-none mb-8">
    {{ expandable_text(node.field_rp_description.processed, lines=4) }}
  </div>
{% endif %}
```

The `.rp-description prose` wrapper stays for styling; the expandable structure nests inside.

On `text_with_summary`: the field *is* `text_with_summary`, so one could imagine showing the editor-authored summary as the collapsed preview instead of clamping. That is not viable here: `display_summary` is `false` on both bundles that carry `field_rp_description` (`access_active_resources_from_cid` and `resource_group`, verified in field config), so the widget never surfaces a summary box to editors and the summary is empty in practice. There is no author-provided short form to show, so the component clamps the full processed value. (This also strengthens the rejection of `smart_trim` in Alternatives — there is no summary for a summary-aware formatter to lean on.)

## Application to the community-persona bio (second consumer)

The community-persona bio currently truncates via `CommunityPersonaController::userBio()` (char-cut at 450 + hand-built More/Less button markup). The bio is then embedded as a `{{ bio_summary |raw }}` variable inside **two separate `inline_template` blocks** — the viewer's own persona (~line 526) and the other-user profile view (~line 805) — each with its own hardcoded `<div id="bio-summary">` / `<div id="full-bio" class="sr-only">` scaffolding and its own `#context`.

Rather than embed a Twig-function call inside those two heredoc template strings (which is workable but fragile — a hand-edit to each string, and a silent-library-drop trap if an implementer substitutes a string-interpolated `#theme` render for the function), **the bio is restructured to a first-class render element.** This is the native Drupal path and makes `#attached` bubbling automatic:

- **Rewrite `userBio()`** to return the full processed bio markup (no char-cut, no hand-built buttons) — a single value, not the `[$bio_summary, $bio]` pair.
- **Build the bio as a `#theme` render element in the controller** and pass it into the existing `inline_template` via `#context` (as a render array, not a pre-rendered string):
  ```php
  $bio_expandable = $bio === '' ? '' : [
    '#theme' => 'expandable_text',
    '#content' => ['#markup' => $bio],
    '#lines' => 4,
  ];
  // ... '#context' => [ ... 'bio_expandable' => $bio_expandable, ... ]
  ```
  A render array placed in `#context` is rendered by Twig's `renderVar()` **inside the inline_template's active render context**, so its `#attached` library bubbles natively — there is no string interpolation and no way to silently drop the library. The library itself is attached by the component's `template_preprocess_expandable_text()` (which fires on every render of the theme hook, for both entry points), so neither consumer has to remember `#attached`. (The trap to avoid: calling `\Drupal::service('renderer')->render($bio_expandable)` in PHP and passing the resulting *string* into `#context` — a rendered string carries no `#attached`. Pass the render array itself.) This consumer uses the component's **`#theme` hook directly** (the resource-intro consumer uses the **Twig function**); exercising both entry points validates the component's dual surface.
- **Replace the bio scaffolding inside both `#template` strings.** In each of the two persona builds (~line 526 and ~line 805), the `{% if bio %}` block currently contains two inner divs — `<div id="bio-summary">{{ bio_summary |raw }}</div>` and `<div id="full-bio" class="sr-only">{{ bio |raw }}</div>`. Replace those two divs with a single `{{ bio_expandable }}` print, and drop `bio_summary`/`bio` from both `#context` arrays in favor of `bio_expandable`. **Keep the surrounding `{% if bio %}` gate and the `{% set skill_margin = "my-3" %}` it drives** — that layout coupling is unrelated to truncation and must not change. The `{% if bio %}` gate keys on a separate boolean; keep passing it (see plan) so an empty bio still hides the whole section and leaves `skill_margin` correct. This is the "redo how the bio is built" churn — two template blocks edited — and it removes the duplication and the drop-trap for good.
- **Remove the dead `cssn.js` block.** Lines 38-46 of `cssn.js` run a `setTimeout(500)` that re-appends More/Less buttons to `.user-profile-view #bio-summary.more`. That selector matches nothing: `userBio()` already emits the `bio-more`/`bio-less` buttons *inline into its returned HTML string*, and no markup ever carries the `.more` class the selector requires. The block is dead code duplicating server-side logic, not a load-order fallback — delete it along with the now-unused `bioMore()`/`bioLess()` functions. Confirm removing `cssn.js`'s library `js` key (if it becomes empty) doesn't orphan a needed `cssn.css` attachment.
- `field_user_bio` is `text_long` with the `basic_html` format, so it can contain `<p>`/`<ul>` — the bio genuinely exercises the component's multi-block handling, not just single-paragraph text.
- **Cross-repo:** the controller and `cssn.js` live in the pinned `connectci/access` repo, so this half is a change there + re-pin + artifact rebuild (the same cycle used elsewhere this codebase). The `#theme` reference crosses the pin but resolves at runtime — Drupal discovers `expandable_text` and `access` from the same `web/modules/custom/` namespace regardless of which arrived via a composer pin, and `cssn.info.yml` already declares a sibling-module dependency (`access`) the same way. The component module and the resource-intro template edit are separate from this.
- **Deploy ordering (hard constraint).** The bio renders `['#theme' => 'expandable_text']` from the pinned `access` repo. If the `access` pin is deployed where the `expandable_text` module is not yet enabled, that render fails (`Theme hook "expandable_text" not found`). Enable the `expandable_text` module **before or with** the `access`-pin deploy, never after. Cypress asserts page behavior, not pin-sync, so it will not catch a mis-ordered deploy — this belongs in the deploy/release notes for the branch. (Enabling the module is a `cssn` dependency worth declaring in `cssn.info.yml` so `drush`/config enforces ordering rather than relying on notes.)

The bio swap replaces the truncation mechanism and restructures how the bio is placed in the build; the persona layout and where the bio visually appears are unchanged.

## Home for the component

**A standalone in-repo custom module** at `web/modules/custom/expandable_text`, built clean enough to publish to drupal.org later (README, no site-specific coupling) but shipped in-repo for now. Publishing is a separate future task, not part of this ticket.

Why standalone rather than bundled into `access_misc`:

- **No coupling to `access`.** The component is a general-purpose UI widget with no dependency on the ACCESS domain. Bundling it into `access_misc` would bury a reusable widget inside a grab-bag module *and* re-introduce the cross-repo problem — `access_misc` lives in the pinned `connectci/access` repo, so both the module and any `#theme` reference to it would straddle the pin.
- **Reusability is real, not speculative.** Two consumers land in this effort (resource intro + persona bio), and they use *different* entry points — the resource intro calls the Twig function from a template, the bio renders the `#theme` element from a controller — which justifies a generic, theme-independent component and pressure-tests both surfaces.
- **Publishable.** No contrib module does automatic line-based clamping of rich HTML (see Alternatives — `smart_trim` is char/word-based and navigates away; `collapsiblock` collapses whole blocks by heading). That gap is exactly what a small published module would fill, so keeping it standalone and un-coupled preserves that option at no extra cost now.

**Cross-repo resolution:** the resource intro (Twig function, in the pinned asp-theme template) and the bio (`#theme` element, in the pinned `access` controller) both reference the in-repo `expandable_text` component across a composer pin. Both resolve at render time because Drupal discovers all of `web/modules/custom/` uniformly — a globally-registered Twig function and a module-provided `#theme` hook are both available regardless of which code arrived via a pin. `cssn.info.yml` already declares a sibling-module dependency (`access`) this exact way, so adding `expandable_text` as a `cssn` dependency (to enforce enable-ordering) follows existing precedent rather than introducing a novel direction.

Cross-repo footprint to plan around — this work touches three repos:
- The **in-repo `expandable_text` module** (the component itself): `web/modules/custom/`, directly editable.
- The **pinned asp-theme repo**: the resource-intro template edit (Twig function call site).
- The **pinned `connectci/access` repo**: the persona bio swap (`CommunityPersonaController::userBio()` + dead-code removal in `cssn.js`).

Both pinned-repo edits need their own commit + re-pin + artifact rebuild. The module home only affects where the component lives; the two wire-ups cross into pinned repos regardless.

## Testing

**Fixture gap (implementation task):** the existing alpha/beta/gamma resource fixtures in `amp_dev.install` are too small to exercise this — alpha has two short `<p>` (~2–3 lines, no heading or list), beta/gamma have one short `<p>`. None overflows 4 lines and none is heading-led or has a `<ul>`, which is exactly the content that breaks the naive mechanisms. A **new or extended fixture is required**: give a test resource a long, heading-led, multi-block `field_rp_description` (an `<h3>` + multiple `<p>` + a `<ul>`, > 4 rendered lines). This is a named implementation-plan task, not an assumed given.

Cypress on `/documentation/resources/{slug}`, extending the existing `accessmatch2/rp-docs/resource-page.cy.js`. Assertions target **behavior and DOM state**, not pixel heights (headless font-loading and viewport make height assertions flaky):

- **Long, multi-paragraph intro** (a fixture whose `field_rp_description` has several `<p>`/`<ul>` — the case that breaks `-webkit-line-clamp`, so it must be exercised explicitly): the toggle button is present with `aria-expanded="false"`; the full text is *not fully visible* (assert via the collapsed class on the container, not a measured height); clicking **More** sets `aria-expanded="true"`, removes the collapsed class, and the previously-hidden trailing paragraph text becomes visible; clicking **Less** re-collapses.
- **Short intro**: no toggle button, no collapsed class.
- Assert the button is a real `<button>` with correct `aria-controls`, for the a11y contract.

**Bio consumer** (extend the existing community-persona spec): a fixture user with a long, multi-block `field_user_bio` shows the same clamp + More/Less behavior on their persona; a user with a short bio shows no toggle. A fixture user with a suitable long bio is needed — the amp_dev dummy users set a short bio, so extend one (or add a dedicated user) as an implementation-plan task. The old `bio-summary`/`full-bio`/`bio-more` markup and the `bioMore()`/`bioLess()`/`setTimeout` block in `cssn.js` must be gone (assert the new `.expandable-text` structure, not the old ids). Because the bio is placed in **two** separate persona builds (own-persona view ~line 526, other-user profile view ~line 805), both must be asserted — a fixture on the own-persona view AND an assertion on the other-user profile view — since the `$build['bio']` element has to be slotted into each and one could be missed.

No PHPUnit: the component is a thin render wrapper; the meaningful behavior is the JS toggle and the two consumers' wiring, which Cypress covers.

## Accessibility

- Full content always in the DOM; collapse is visual only.
- Toggle is a real `<button>` with `aria-expanded` reflecting state and `aria-controls` referencing the content region by unique id.
- Button labels are translatable — `Drupal.t('More')` / `Drupal.t('Less')`.
- **Hidden overflow is made non-tabbable when collapsed** (`inert` on the content region, cleared on expand), so keyboard users cannot tab into the clipped-but-in-DOM text/links.
- Button appears only when the content actually overflows N lines.
- Degrades to full text with JS disabled — the collapse is applied only by JS, so there is no CSS that could trap content on unsupported browsers.
