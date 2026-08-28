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

### Clamping a table by rows

Pass a third argument (or `#rows`) to clamp on a `tbody > tr` boundary instead
of a text line:

    {{ expandable_text(table_render_array, 4, 5) }}

Line measurement is wrong for a data table — top-aligned cells of unequal
height and graphics-only cells mean N lines is neither N rows nor a row
boundary — so `rows` measures rows directly and never cuts through one. Content
with no table rows falls back to the `lines` measurement, so it is safe to set
both. In row mode only the rows below the clamp are made `inert`, not the whole
content box, so a table inside a horizontal scroller stays scrollable and
readable while collapsed.

Note that markup handed in as a *string* goes through `Xss::filterAdmin()`,
which strips `<svg>` and `style` attributes. A table containing inline SVG must
be passed as a render array, or the wrapper markup (`.expandable-text` >
`.expandable-text__content`, plus `attach_library`) written out in the template
directly.

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
