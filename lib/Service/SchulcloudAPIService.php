<?php
/**
 * Nextcloud - schulcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Schulcloud\Service;

use OCP\IL10N;
use OCP\ILogger;
use OCP\Http\Client\IClientService;

class SchulcloudAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Schulcloud v3 (JSON) API
	 */
	public function __construct (
		string $appName,
		ILogger $logger,
		IL10N $l10n,
		IClientService $clientService
	) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->clientService = $clientService;
		$this->client = $clientService->newClient();
	}

	public function getNotifications(string $url,
									string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
									?string $since) {
		$result = $this->request($url, $accessToken, $refreshToken, $clientID, $clientSecret, 'notifications.json', $params);
		if (!is_array($result)) {
			return $result;
		}
		$notifications = [];
		if (isset($result['notifications']) and is_array($result['notifications'])) {
			foreach ($result['notifications'] as $notification) {
				array_push($notifications, $notification);
			}
		}

		return $notifications;
	}

	public function getSchulcloudAvatar(string $url,
										string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
										string $username) {
		$result = $this->request($url, $accessToken, $refreshToken, $clientID, $clientSecret, 'users/'.$username.'.json');
		if (is_array($result) and isset($result['user']) and isset($result['user']['avatar_template'])) {
			$avatarUrl = $url . str_replace('{size}', '32', $result['user']['avatar_template']);
			return $this->client->get($avatarUrl)->getBody();
		}
		return '';
	}

	public function request(string $url,
							string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
							string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'User-Api-Key' => $accessToken,
					// optional
					//'User-Api-Client-Id' => $clientId,
					'User-Agent' => 'Nextcloud Schulcloud integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);

					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$this->logger->warning('Schulcloud API error : '.$e, array('app' => $this->appName));
			$response = $e->getResponse();
			$body = (string) $response->getBody();
			// refresh token if it's invalid and we are using oauth
			if (strpos($body, 'expired') !== false) {
				$this->logger->warning('Trying to REFRESH the access token', array('app' => $this->appName));
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($url, [
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
					// retry the request with new access token
					return $this->request(
						$url, $accessToken, $refreshToken, $clientID, $clientSecret, $endPoint, $params, $method
					);
				}
			}
			return ['error' => $e->getMessage()];
		}
	}

	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/oauth2/token';
			$options = [
				'headers' => [
					'User-Agent'  => 'Nextcloud Schulcloud integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Schulcloud OAuth error : '.$e, array('app' => $this->appName));
			return ['error' => $e->getMessage()];
		}
	}
}
