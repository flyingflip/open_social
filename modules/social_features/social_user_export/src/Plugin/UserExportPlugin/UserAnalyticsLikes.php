<?php

namespace Drupal\social_user_export\Plugin\UserExportPlugin;

use Drupal\social_user_export\Plugin\UserExportPluginBase;
use Drupal\user\UserInterface;

/**
 * Provides a 'UserAnalyticsLikes' user export row.
 *
 * @UserExportPlugin(
 *  id = "user_likes",
 *  label = @Translation("Number of Likes"),
 *  weight = -190,
 * )
 */
class UserAnalyticsLikes extends UserExportPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getHeader() {
    return $this->t('Number of Likes');
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(UserInterface $entity) {
    $user_id = $entity->id();
    if (!$user_id) {
      return "0";
    }

    $query = $this->database->select('votingapi_vote', 'v');
    $query->condition('v.user_id', (string) $user_id);

    $result = $query
      ->countQuery()
      ->execute();
    if ($result === NULL) {
      return "0";
    }

    // Cast to int first so an empty result registers a "0". We cast to string
    // to satisfy the user export plugin interface.
    return (string) (int) $result->fetchField();
  }

}
