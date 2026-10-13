<?php

namespace Drupal\Tests\ood_software\Unit\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ood_software\Service\ReviewAssignment;
use Drupal\user\UserInterface;

/**
 * Who can review a repo, and when an assignment is saved.
 *
 * @group ood_software
 *
 * @coversDefaultClass \Drupal\ood_software\Service\ReviewAssignment
 */
class ReviewAssignmentTest extends UnitTestCase {

  /**
   * The node storage, so a test can assert it is never asked to save.
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * A service whose reviewers are the given uid => display name.
   */
  protected function service(array $reviewers): ReviewAssignment {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_combine(array_keys($reviewers), array_keys($reviewers)));
    $users = [];
    foreach ($reviewers as $uid => $name) {
      $user = $this->createMock(UserInterface::class);
      $user->method('id')->willReturn($uid);
      $user->method('getDisplayName')->willReturn($name);
      $users[$uid] = $user;
    }
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage->method('getQuery')->willReturn($query);
    $userStorage->method('loadMultiple')->willReturn($users);
    $this->nodeStorage = $this->createMock(EntityStorageInterface::class);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnMap([['user', $userStorage], ['node', $this->nodeStorage]]);
    return new ReviewAssignment($etm, $this->createMock(AccountInterface::class), $this->createMock(LoggerChannelFactoryInterface::class));
  }

  /**
   * A repo assigned to $uid (or no one).
   */
  protected function repo(?int $uid): NodeInterface {
    $assignee = NULL;
    if ($uid !== NULL) {
      $assignee = $this->createMock(UserInterface::class);
      $assignee->method('id')->willReturn($uid);
    }
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('__get')->with('entity')->willReturn($assignee);
    $repo = $this->createMock(NodeInterface::class);
    $repo->method('hasField')->willReturn(TRUE);
    $repo->method('get')->willReturn($field);
    return $repo;
  }

  /**
   * Sorted by name; a shared display name gets the user id.
   *
   * @covers ::reviewers
   */
  public function testReviewers(): void {
    $this->assertSame(
      [7 => 'Ann', 1 => 'Lux Rivers (user 1)', 3465 => 'Lux Rivers (user 3465)', 2 => 'zed'],
      $this->service([1 => 'Lux Rivers', 2 => 'zed', 3465 => 'Lux Rivers', 7 => 'Ann'])->reviewers(),
    );
  }

  /**
   * Only a reviewer can be assigned, and an unchanged assignment is not saved
   * (a save of a queued repo must never look like a re-review).
   *
   * @covers ::assign
   */
  public function testAssignGuards(): void {
    $service = $this->service([5 => 'Rev']);
    $this->nodeStorage->expects($this->never())->method('loadUnchanged');
    $this->assertFalse($service->assign($this->repo(NULL), 99), 'not a reviewer');
    $this->assertFalse($service->assign($this->repo(5), 5), 'already assigned');
    $this->assertFalse($service->assign($this->repo(NULL), NULL), 'already unassigned');
  }

}
