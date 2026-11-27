<?php

namespace Drupal\reg_core\EventSubscriber;

use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\reg_core\Translation\TranslationOnlyRole;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Opens the translation workspace instead of the translator's profile.
 *
 * Does not intercept login, logout, password reset, MFA or form submissions.
 */
final class TranslationOnlyLandingSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['redirectProfile', 0]];
  }

  public function redirectProfile(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$event->getRequest()->isMethod('GET')) {
      return;
    }
    $account = \Drupal::currentUser();
    if (!$account->isAuthenticated()
      || !in_array(TranslationOnlyRole::ID, $account->getRoles(), TRUE)
      || !$account->hasPermission('view reg translation report')) {
      return;
    }
    $route = \Drupal::routeMatch();
    if ($route->getRouteName() === 'entity.user.canonical') {
      $user = $route->getParameter('user');
      if (!$user instanceof \Drupal\user\UserInterface || (string) $user->id() !== (string) $account->id()) {
        return;
      }
    }
    elseif ($route->getRouteName() !== 'user.page') {
      return;
    }
    $url = Url::fromRoute('reg_core.translation_report');
    if (!$url->access($account)) {
      return;
    }
    $response = new LocalRedirectResponse($url->toString());
    $response->headers->set('Cache-Control', 'private, no-store');
    $event->setResponse($response);
  }

}
