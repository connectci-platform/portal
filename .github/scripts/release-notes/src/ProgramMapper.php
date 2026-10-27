<?php

/**
 * Picks the program a ticket was built for from its sprints.
 *
 * A ticket's sprint field lists every sprint it has ever been in, not in date
 * order, and many are named by date ("Jan 17") or purpose ("Testing"). The
 * sprint with the latest start date whose name matches a rule wins.
 */
final class ProgramMapper {

  /**
   * @param list<array{pattern: string, program: string}> $rules
   *   Matched case-insensitively, in order.
   */
  public function __construct(
    private array $rules,
    private string $default,
  ) {}

  public static function fromFile(string $path): self {
    $data = json_decode((string) file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
    return new self($data['rules'], $data['default']);
  }

  /**
   * @param list<array<string, mixed>> $sprints
   *   The raw sprint field: name and startDate (ISO, or NULL).
   */
  public function programFor(array $sprints): string {
    usort($sprints, fn ($a, $b) => strcmp((string) ($b['startDate'] ?? ''), (string) ($a['startDate'] ?? '')));
    foreach ($sprints as $sprint) {
      $program = $this->match((string) ($sprint['name'] ?? ''));
      if ($program !== NULL) {
        return $program;
      }
    }
    return $this->default;
  }

  /**
   * The program a sprint name maps to, or NULL when no rule matches.
   */
  public function match(string $name): ?string {
    foreach ($this->rules as $rule) {
      if (preg_match('/' . str_replace('/', '\/', $rule['pattern']) . '/i', $name)) {
        return $rule['program'];
      }
    }
    return NULL;
  }

  public function default(): string {
    return $this->default;
  }

}
