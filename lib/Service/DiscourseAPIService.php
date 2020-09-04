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

    public function getNotifications($url, $accessToken, $since = null) {
        $result = $this->request($url, $accessToken, 'notifications.json', $params);
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

    public function getSchulcloudAvatar($url, $accessToken, $username) {
        $result = $this->request($url, $accessToken, 'users/'.$username.'.json');
        if (is_array($result) and isset($result['user']) and isset($result['user']['avatar_template'])) {
            $avatarUrl = $url . str_replace('{size}', '32', $result['user']['avatar_template']);
            return $this->client->get($avatarUrl)->getBody();
        }
        return '';
    }

    public function request($url, $accessToken, $endPoint, $params = [], $method = 'GET') {
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
                return $this->l10n->t('Bad credentials');
            } else {
                return json_decode($body, true);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Schulcloud API error : '.$e, array('app' => $this->appName));
            return $e;
        }
    }

}