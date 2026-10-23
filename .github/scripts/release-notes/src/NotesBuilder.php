<?php

/**
 * Turns released PRs and their Jira tickets into release note items.
 *
 * Each item is one line of the notes:
 * section, program, text, key (NULL when the line must not show it) and the
 * PR numbers that shipped it.
 */
final class NotesBuilder {

  private const TYPE_SECTIONS = ['Story' => 'Features', 'Bug' => 'Fixes'];

  private const LABEL_SECTIONS = ['feature' => 'Features', 'fix' => 'Fixes', 'maintenance' => 'Maintenance'];

  public function __construct(private ProgramMapper $programs) {}

  /**
   * @param list<array<string, mixed>> $prs
   *   Released PRs, from ReleaseWindow::select().
   * @param array<string, array<string, mixed>>|null $tickets
   *   JiraClient::fetch() output, or NULL when Jira could not be reached.
   *
   * @return list<array{section: string, program: string, text: string, key: ?string, prs: list<int>}>
   */
  public function build(array $prs, ?array $tickets): array {
    // Group PRs by ticket, keeping first-seen order. PRs with no ticket stand
    // alone under their own PR number.
    $groups = [];
    foreach ($prs as $pr) {
      $labels = array_map(fn ($l) => $l['name'], $pr['labels'] ?? []);
      if (in_array('skip-release-notes', $labels, TRUE)) {
        continue;
      }
      $key = TicketKeys::fromPr($pr);
      $id = $key ?? '#' . $pr['number'];
      $groups[$id] ??= ['key' => $key, 'title' => $pr['title'], 'labels' => [], 'prs' => []];
      $groups[$id]['labels'] = array_values(array_unique([...$groups[$id]['labels'], ...$labels]));
      $groups[$id]['prs'][] = (int) $pr['number'];
    }

    // A sub-task shipped with its parent folds into the parent's line.
    foreach ($groups as $id => $group) {
      $parent = $group['key'] !== NULL ? ($tickets[$group['key']]['parent'] ?? NULL) : NULL;
      if ($parent !== NULL && isset($groups[$parent])) {
        $groups[$parent]['prs'] = [...$groups[$parent]['prs'], ...$group['prs']];
        $groups[$parent]['labels'] = array_values(array_unique([...$groups[$parent]['labels'], ...$group['labels']]));
        unset($groups[$id]);
      }
    }

    $items = [];
    foreach ($groups as $group) {
      $prNumbers = $group['prs'];
      sort($prNumbers);
      $items[] = ['prs' => $prNumbers] + $this->line($group, $tickets);
    }
    return array_map(fn ($i) => [
      'section' => $i['section'],
      'program' => $i['program'],
      'text' => $i['text'],
      'key' => $i['key'],
      'prs' => $i['prs'],
    ], $items);
  }

  /**
   * @param array{key: ?string, title: string, labels: list<string>, prs: list<int>} $group
   * @param array<string, array<string, mixed>>|null $tickets
   *
   * @return array{section: string, program: string, text: string, key: ?string}
   */
  private function line(array $group, ?array $tickets): array {
    $key = $group['key'];
    $labels = $group['labels'];
    $default = $this->programs->default();

    if ($key === NULL) {
      return ['section' => $this->labelSection($labels) ?? 'Maintenance', 'program' => $default, 'text' => $group['title'], 'key' => NULL];
    }

    $ticket = $tickets[$key] ?? NULL;
    $program = $ticket !== NULL ? $this->programs->programFor($ticket['sprints'] ?? []) : $default;

    if (in_array('security', $labels, TRUE) || in_array('security', $ticket['labels'] ?? [], TRUE)) {
      return ['section' => 'Fixes', 'program' => $program, 'text' => 'Security hardening', 'key' => NULL];
    }
    if ($ticket === NULL) {
      return ['section' => $this->labelSection($labels) ?? 'Improvements', 'program' => $default, 'text' => "Update (details in $key)", 'key' => NULL];
    }
    $section = $this->labelSection($labels) ?? self::TYPE_SECTIONS[$ticket['type']] ?? 'Improvements';
    return ['section' => $section, 'program' => $program, 'text' => $ticket['summary'], 'key' => $key];
  }

  /**
   * @param list<string> $labels
   */
  private function labelSection(array $labels): ?string {
    foreach (self::LABEL_SECTIONS as $label => $section) {
      if (in_array($label, $labels, TRUE)) {
        return $section;
      }
    }
    return NULL;
  }

}
