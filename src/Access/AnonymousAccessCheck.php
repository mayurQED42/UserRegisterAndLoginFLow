<?php

namespace Drupal\register_user\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Access check for anonymous users.
 */
class AnonymousAccessCheck {

  /**
   * Checks access for anonymous users.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // Allow access if the user is anonymous.
    return $account->isAnonymous() ? AccessResult::allowed() : AccessResult::forbidden();
  }

}
