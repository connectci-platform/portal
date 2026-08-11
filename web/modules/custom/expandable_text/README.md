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
