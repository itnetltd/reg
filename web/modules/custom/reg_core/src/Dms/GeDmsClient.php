<?php

namespace Drupal\reg_core\Dms;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Server-side HTTP client for the General Electric DMS outage API.
 */
final class GeDmsClient implements DmsClientInterface {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function fetchOutages(): array {
    [$url, $options] = $this->requestConfiguration();

    try {
      $response = $this->httpClient->request('GET', $url, $options);
      $status = $response->getStatusCode();
      if ($status < 200 || $status >= 300) {
        throw new DmsException(sprintf('GE DMS returned HTTP %d.', $status));
      }

      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($payload)) {
        throw new DmsException('GE DMS returned an unsupported payload.');
      }
      return $payload;
    }
    catch (DmsException $exception) {
      throw $exception;
    }
    catch (GuzzleException | \JsonException $exception) {
      $this->logger->warning('GE DMS outage request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new DmsException('GE DMS is temporarily unavailable.', 0, $exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function testConnectivity(): array {
    try {
      [$url, $options] = $this->requestConfiguration();
      $options['query']['limit'] = 1;
      $response = $this->httpClient->request('GET', $url, $options);
      $status = $response->getStatusCode();
      $success = $status >= 200 && $status < 300;
      return [
        'success' => $success,
        'status_code' => $status,
        'message' => $success
          ? 'GE DMS connectivity test succeeded.'
          : sprintf('GE DMS returned HTTP %d.', $status),
      ];
    }
    catch (DmsException $exception) {
      return [
        'success' => FALSE,
        'status_code' => NULL,
        'message' => $exception->getMessage(),
      ];
    }
    catch (GuzzleException $exception) {
      $this->logger->notice('GE DMS connectivity test failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return [
        'success' => FALSE,
        'status_code' => NULL,
        'message' => 'GE DMS could not be reached with the current server-side configuration.',
      ];
    }
  }

  /**
   * Builds credential-bearing options for server-side use only.
   *
   * @return array{0: string, 1: array<string, mixed>}
   *   The URL and Guzzle request options.
   */
  private function requestConfiguration(): array {
    $config = $this->configFactory->get('reg_core.settings');
    $base_url = rtrim(trim((string) $config->get('dms.api_base_url')), '/');
    if ($base_url === '') {
      throw new DmsException('GE DMS API base URL is not configured.');
    }
    $path = '/' . ltrim(trim((string) ($config->get('dms.outages_path') ?: '/outages')), '/');
    $url = $base_url . $path;

    $timeout = max(1, min(120, (int) ($config->get('dms.request_timeout') ?: 10)));
    $headers = [
      'Accept' => 'application/json',
      'User-Agent' => 'REG-Drupal-DMS/1.0',
    ];
    $method = (string) ($config->get('dms.authentication_method') ?: 'none');
    $client_id = trim((string) $config->get('dms.client_id'));
    $credential_variable = trim((string) ($config->get('dms.credential_environment_variable') ?: 'REG_GE_DMS_CREDENTIAL'));
    if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $credential_variable)) {
      throw new DmsException('GE DMS credential environment variable name is invalid.');
    }
    $environment_value = getenv($credential_variable);
    $credential = is_string($environment_value) ? trim($environment_value) : '';

    switch ($method) {
      case 'api_key':
        if ($credential === '') {
          throw new DmsException('GE DMS API credential is not available in the server environment.');
        }
        $headers['X-API-Key'] = $credential;
        break;

      case 'bearer':
        if ($credential === '') {
          throw new DmsException('GE DMS bearer credential is not available in the server environment.');
        }
        $headers['Authorization'] = 'Bearer ' . $credential;
        break;

      case 'client_id':
        if ($client_id === '') {
          throw new DmsException('GE DMS client ID is not configured.');
        }
        $headers['X-Client-Id'] = $client_id;
        break;

      case 'none':
        break;

      default:
        throw new DmsException('GE DMS authentication method is not supported.');
    }

    return [
      $url,
      [
        'headers' => $headers,
        'timeout' => $timeout,
        'connect_timeout' => min(5, $timeout),
        'http_errors' => FALSE,
      ],
    ];
  }

}
