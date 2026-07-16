<?php

namespace Drupal\ctt\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authentication endpoints for the CTT standalone UI.
 */
final class CttAuthController implements ContainerInjectionInterface {

  public function __construct(
    private readonly UserAuthInterface $userAuth,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.auth'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Logs in a user by validating credentials against Drupal.
   *
   * Request JSON: {"username": "...", "password": "..."}
   * Response JSON envelope:
   * - { isSuccessful: true, body: { id, name, email, roles } }
   * - { isSuccessful: false, body: "..." }
   */
  public function login(Request $request): JsonResponse {
    $payload = json_decode($request->getContent() ?: '', TRUE);
    if (!is_array($payload)) {
      return $this->jsonEnvelope(FALSE, 'Invalid JSON body.', 400);
    }

    $username = isset($payload['username']) ? trim((string) $payload['username']) : '';
    $password = isset($payload['password']) ? (string) $payload['password'] : '';

    if ($username === '' || $password === '') {
      return $this->jsonEnvelope(FALSE, 'Username and password are required.', 400);
    }

    // Authenticate using Drupal's user auth service.
    $uid = $this->userAuth->authenticate($username, $password);
    if (empty($uid) || !is_numeric($uid)) {
      return $this->jsonEnvelope(FALSE, 'Invalid username or password.', 401);
    }

    /** @var \Drupal\user\UserInterface|null $account */
    $account = $this->entityTypeManager->getStorage('user')->load((int) $uid);
    if (!$account instanceof UserInterface) {
      return $this->jsonEnvelope(FALSE, 'Authenticated user not found.', 500);
    }

    // Use the Drupal user email as the canonical identity for HASCOAPI calls.
    $email = (string) $account->getEmail();
    $name = (string) $account->getAccountName();
    $roles = array_values(array_unique($account->getRoles()));

    return $this->jsonEnvelope(TRUE, [
      'id' => (int) $account->id(),
      'name' => $name,
      'email' => $email,
      'roles' => $roles,
    ], 200);
  }

  private function jsonEnvelope(bool $isSuccessful, mixed $body, int $statusCode): JsonResponse {
    $response = new JsonResponse([
      'isSuccessful' => $isSuccessful,
      'body' => $body,
    ], $statusCode);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

}
