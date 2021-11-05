<?php

namespace Drupal\alternative_frontpage\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\Core\Path\PathMatcher;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\State\State;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\user\UserData;
use Drupal\Core\Session\AccountProxy;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Installer\InstallerKernel;

/**
 * Class RedirectHomepageSubscriber.
 */
class RedirectHomepageSubscriber implements EventSubscriberInterface {

  /**
   * Protected var UserData.
   *
   * @var \Drupal\user\UserData
   */
  protected $userData;

  /**
   * The config factory to perform operations on the configs.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Protected var for the current user.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * Protected var for the path matcher.
   *
   * @var \Drupal\Core\Path\PathMatcher
   */
  protected $pathMatcher;

  /**
   * Protected var entityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The state.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * Constructor for the RedirectHomepageSubscriber.
   *
   * @param \Drupal\user\UserData $user_data
   *   User data.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   Config factory.
   * @param \Drupal\Core\Session\AccountProxy $current_user
   *   The current user.
   * @param \Drupal\Core\Path\PathMatcher $path_matcher
   *   The path matcher.
   * @param \Drupal\Core\State\State $state
   *   The state.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(UserData $user_data, ConfigFactory $config_factory, AccountProxy $current_user, PathMatcher $path_matcher, State $state, EntityTypeManagerInterface $entity_type_manager, LanguageManagerInterface $language_manager) {
    // We needs it.
    $this->userData = $user_data;
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->pathMatcher = $path_matcher;
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // 280 priority is higher than the dynamic and static page cache.
    $events[KernelEvents::REQUEST][] = ['checkForHomepageRedirect'];
    return $events;
  }

  /**
   * This method is called whenever the request event is dispatched.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   */
  public function checkForHomepageRedirect(GetResponseEvent $event): void {
    // Make sure front page module is not run when using cli or doing install.
    if (PHP_SAPI === 'cli' || InstallerKernel::installationAttempted()) {
      return;
    }
    // Don't run when site is in maintenance mode.
    if ($this->state->get('system.maintenance_mode')) {
      return;
    }

    // Ignore non index.php requests (like cron).
    if (!empty($_SERVER['SCRIPT_FILENAME']) && realpath(DRUPAL_ROOT . '/index.php') != realpath($_SERVER['SCRIPT_FILENAME'])) {
      return;
    }

    // No redirection is required for administrators. Ideally, we want to
    // check the permission (for example, "administer site configuration"),
    // but SM also has this permission, and we have no other permission to
    // check if the user is an administrator.
    if (in_array('administrator', $this->currentUser->getRoles(), TRUE)) {
      return;
    }

    $request = $event->getRequest();
    $request_path = $request->getPathInfo();
    $site_settings_frontpage = $this->configFactory->get('system.site')->get('page.front');

    $current_user_roles = $this->currentUser->getRoles(FALSE);

    $current_user_data = $this->getAlternativePageDataForCurrentUser($current_user_roles);
    $user_role = $current_user_data['role'];
    $user_page = $current_user_data['page'];

    $active_language = $this->languageManager->getCurrentLanguage()->getId();
    $default_language = $this->languageManager->getDefaultLanguage()->getId();

    // Do nothing if the current user does not have a custom front page.
    if (!$user_page) {
      return;
    }

    // We do not redirect if the user is already on the desired page,
    // otherwise there will be an endless loop.
    if ($request_path === $user_page) {
      return;
    }

    // We only proceed if the user requests the home page.
    if ($request_path === $site_settings_frontpage || $request_path === '/' || $request_path === '/' . $active_language) {
      if ($user_page === $site_settings_frontpage && $this->pathMatcher->isFrontPage()) {
        return;
      }

      $redirect_path = $user_page;

      // The custom user page is different from the home page from
      // the site settings.
      if ($user_page !== $site_settings_frontpage) {
        if ($active_language !== $default_language) {
          $redirect_path = '/' . $active_language . $user_page;
        }

        $cache_contexts = new CacheableMetadata();
        $cache_contexts->setCacheContexts(['user.roles:' . $user_role]);
      }
      // This means that the user has several roles and, accordingly, the
      // front pages are set for these several roles, in this case we check
      // the role weight and use a role with a higher weight.
      else {
        $current_user_role_weight = [];

        foreach ($current_user_roles as $current_user_role) {
          /** @var \Drupal\user\RoleInterface $role */
          $role = $this->entityTypeManager->getStorage('user_role')->load($current_user_role);
          $current_user_role_weight[$current_user_role] = $role->getWeight();
        }

        $role_id_with_higher_weight = (string) array_search(max($current_user_role_weight), $current_user_role_weight, TRUE);
        $user_front_pages = $this->getAlternativePagesForUserRoles([$role_id_with_higher_weight]);

        $user_page = (string) reset($user_front_pages);

        if ($active_language !== $default_language) {
          $redirect_path = '/' . $active_language . $user_page;
        }

        $cache_contexts = new CacheableMetadata();
        $cache_contexts->setCacheContexts(['user.roles:' . $role_id_with_higher_weight]);
      }

      $response = new CacheableRedirectResponse($redirect_path);
      $response->addCacheableDependency($cache_contexts);
      $event->setResponse($response);
    }
  }

  /**
   * Get the alternative configs based on provided role.
   */
  public function getConfigIds($role) {
    $entity = $this->entityTypeManager->getStorage('alternative_frontpage')->getQuery()
      ->condition('roles_target_id', $role)
      ->execute();

    return key($entity);
  }

  /**
   * Get the correct Url Path for the landing page.
   */
  public function getConfigUrlPath(string $role) {
    $active_language = $this->languageManager->getCurrentLanguage()->getId();

    // Get the frontpage for logged in users.
    $config_name = $this->getConfigIds($role);

    // Get the target language object.
    $language = $this->languageManager->getLanguage($active_language);
    // Remember original language before this operation.
    $original_language = $this->languageManager->getConfigOverrideLanguage();
    // Set the translation target language on the configuration factory.
    $this->languageManager->setConfigOverrideLanguage($language);

    // Get the language config override.
    $path = $this->configFactory->get('alternative_frontpage.alternative_frontpage.' . $config_name);
    $path = $path->get('path');

    // Set the configuration language back.
    $this->languageManager->setConfigOverrideLanguage($original_language);

    return $path;
  }

  /**
   * Gets all custom front pages for list of roles.
   *
   * @param string[] $current_user_roles
   *   The array of user roles.
   *
   * @return string[]
   *   The array of relative URLs.
   */
  private function getAlternativePagesForUserRoles(array $current_user_roles): array {
    foreach ($current_user_roles as $role) {
      if (!empty($page = $this->getConfigUrlPath($role))) {
        $pages[$role] = $page;
      }
    }

    return $pages ?? [];
  }


  private function getAlternativePageDataForCurrentUser(array $current_user_roles) {
    $user_front_pages = $this->getAlternativePagesForUserRoles($current_user_roles);

    if (empty($user_front_pages)) {
      return NULL;
    }

    // This means that there is only one page configured for all current
    // user roles, and we can redirect them to this front page.
    if (count($user_front_pages) === 1) {
      return ['role' => array_key_first($user_front_pages), 'page' => reset($user_front_pages)];
    }

    // This means that the user has several roles and, accordingly, the
    // front pages are set for these several roles, in this case we check
    // the role weight and use a role with a higher weight.
    $current_user_role_weight = [];

    foreach ($current_user_roles as $current_user_role) {
      /** @var \Drupal\user\RoleInterface $role */
      $role = $this->entityTypeManager->getStorage('user_role')->load($current_user_role);
      $current_user_role_weight[$current_user_role] = $role->getWeight();
    }

    $role_id_with_higher_weight = (string) array_search(max($current_user_role_weight), $current_user_role_weight, TRUE);
    $user_front_pages = $this->getAlternativePagesForUserRoles([$role_id_with_higher_weight]);

    return ['role' => array_key_first($user_front_pages), 'page' => reset($user_front_pages)];
  }

}
