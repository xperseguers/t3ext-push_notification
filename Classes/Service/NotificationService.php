<?php
/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\PushNotification\Service;

use Causal\PushNotification\Exception\InvalidApiKeyException;
use Causal\PushNotification\Exception\InvalidCertificateException;
use Causal\PushNotification\Exception\InvalidCertificatePassphraseException;
use Causal\PushNotification\Exception\InvalidGatewayException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Notification service.
 *
 * @category    Service
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class NotificationService implements \TYPO3\CMS\Core\SingletonInterface
{
    private $extKey = 'push_notification';

    /**
     * Returns a singleton of this class.
     *
     * @return NotificationService
     * @api
     */
    public static function getInstance(): self
    {
        /** @var NotificationService $instance */
        static $instance = null;

        if ($instance === null) {
            $instance = GeneralUtility::makeInstance(__CLASS__);
        }

        return $instance;
    }

    /**
     * Registers a device.
     *
     * @param string $token Straight token string as fetched from the mobile device
     * @param int $userId Your own internal user identifier to be used when notifying her/him
     * @param string $mode Either 'P' for production or 'D' for development
     * @api
     */
    public function registerDevice(string $token, int $userId, string $mode = 'P'): void
    {
        $this->registerDevices([[$token, $userId, $mode]]);
    }

    /**
     * Registers a set of devices.
     *
     * @param array $tokenUserIds Array of tuplets from token (position 0) and userId (position 1), possibly mode (position 2 where 'P' is production and 'D' development)
     * @api
     */
    public function registerDevices(array $tokenUserIds): void
    {
        $table = 'tx_pushnotification_tokens';

        foreach ($tokenUserIds as $tokenUserId) {
            $token = trim($tokenUserId[0]);
            if (strpos($token, '{length = 32, bytes = ') === 0) {
                // Broken format since iOS 13 without fix in mobile application
                // This token is incomplete and cannot be "extracted" anyway
                continue;
            }
            $userId = (int)$tokenUserId[1];
            $mode = ($tokenUserId[2] ?? 'P') === 'D' ? 'D' : 'P';

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($table);
            $query = $queryBuilder
                ->insert($table)
                ->setValue('token', $queryBuilder->quote($token), false)
                ->setValue('user_id', $userId, false)
                ->setValue('mode', $queryBuilder->quote($mode), false)
                ->setValue('tstamp', $GLOBALS['EXEC_TIME'], false)
                ->getSQL();
            $query = 'REPLACE ' . substr($query, 7);
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable($table)
                ->executeQuery($query);
        }
    }

    /**
     * Unregisters a device.
     *
     * @param string $token
     * @param bool|null $isProductionToken Optional argument to specify if token is production (vs development)
     * @api
     */
    public function unregisterDevice(string $token, ?bool $isProductionToken = null): void
    {
        $this->unregisterDevices([$token], $isProductionToken);
    }

    /**
     * Unregisters multiple devices.
     *
     * @param array $tokens
     * @param bool|null $areProductionTokens Optional argument to specify if token is production (vs development)
     * @api
     */
    public function unregisterDevices(array $tokens, ?bool $areProductionTokens = null): void
    {
        if (empty($tokens)) {
            return;
        }

        $table = 'tx_pushnotification_tokens';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        $conditions = [];
        foreach ($tokens as $token) {
            $conditions[] = $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($token, \PDO::PARAM_STR));
            $sanitizedToken = chunk_split(str_replace(' ', '', $token), 8, ' ');
            $conditions[] = $queryBuilder->expr()->eq('token', $queryBuilder->createNamedParameter($sanitizedToken, \PDO::PARAM_STR));

            static::getLogger()->debug('Unregister device', [
                'token' => $token,
            ]);
        }

        $conditions = [
            $queryBuilder->expr()->orX(... $conditions)
        ];

        if ($areProductionTokens !== null) {
            $conditions[] = $queryBuilder->expr()->eq(
                'mode',
                $queryBuilder->createNamedParameter($areProductionTokens ? 'P' : 'D')
            );
        }

        $queryBuilder
            ->delete($table)
            ->where(... $conditions)
            ->execute();
    }

    /**
     * Drops stale tokens (older than 3 months).
     *
     * @api
     */
    public function removeStaleTokens(): void
    {
        $table = 'tx_pushnotification_tokens';
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        $queryBuilder
            ->delete($table)
            ->where(
                $queryBuilder->expr()->lt('tstamp', strtotime('-3 months'))
            )
            ->execute();
    }

    /**
     * Returns the device tokens registered for a given user.
     *
     * @param int $userId
     * @return array
     * @api
     */
    public function getTokens(int $userId): array
    {
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_pushnotification_tokens')
            ->select(
                ['token', 'mode'],
                'tx_pushnotification_tokens',
                [
                    'user_id' => $userId,
                ]
            )
            ->fetchAllAssociative();
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[] = $row;
        }

        return $tokens;
    }

    /**
     * Notifies a given user on all their registered devices.
     *
     * @param int $notificationId
     * @param int $userId
     * @param string $title
     * @param string $subtitle
     * @param string $message
     * @param bool $sound
     * @param int $badge
     * @param array $extra
     * @return int Number of notification sent (-1 if no notification needed)
     */
    public function notify(
        int    $notificationId,
        int    $userId,
        string $title,
        string $subtitle,
        string $message,
        bool   $sound = true,
        int    $badge = 0,
        array  $extra = []
    ): int
    {
        $tokens = $this->getTokens($userId);
        if (empty($tokens)) {
            // No need to notify
            return -1;
        }

        static::getLogger()->debug('Notifying user', [
            'user' => $userId,
            'notification' => $notificationId,
            'title' => $title,
            'message' => $message,
        ]);

        $count = 0;
        $googleDeviceTokens = [];

        foreach ($tokens as $tokenData) {
            // Normalize the token
            $token = str_replace(' ', '', $tokenData['token']);
            $productionToken = $tokenData['mode'] !== 'D';
            if (strlen($token) === 64) {
                // iOS
                $count += $this->notifyiOS($notificationId, $token, $title, $message, $sound, $badge, $extra, true, $productionToken);
                // According to https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/APNsProviderAPI.html:
                //
                // Keep your connections with APNs open across multiple notifications; don’t repeatedly open and close connections.
                // APNs treats rapid connection and disconnection as a denial-of-service attack. You should leave a connection open
                // unless you know it will be idle for an extended period of time—for example, if you only send notifications to
                // your users once a day it is ok to use a new connection each day.
                //
                // Since we currently do not support grouped notification using the same stream, try to avoid the DoS:
                sleep(10);
            } else {
                // Android
                $googleDeviceTokens[] = $token;
            }
        }

        if (!empty($googleDeviceTokens)) {
            // GCM lets us send a message to multiple devices at once
            $count += $this->notifyGCM($googleDeviceTokens, $title, $message, $sound, $extra);
        }

        return $count;
    }

    /**
     * Sends a notification using GCM.
     *
     * @param array|string $deviceTokens
     * @param string $title
     * @param string $message
     * @param bool $notify
     * @return int Number of notification sent
     * @throws InvalidGatewayException
     * @throws InvalidApiKeyException
     */
    protected function notifyGCM($deviceTokens, string $title, string $message, bool $notify, array $extra): int
    {
        $gateway = 'https://fcm.googleapis.com/fcm/send';

        if (!is_array($deviceTokens)) {
            $deviceTokens = [$deviceTokens];
        }

        $apiAccessKey = $this->getGCMAccessKey();
        if (strlen($apiAccessKey) < 8) {
            static::getLogger()->error('Invalid GCM API key');
            throw new InvalidApiKeyException();
        }

        $payload = json_encode([
            'registration_ids' => $deviceTokens,
            'data' => array_merge(
                $extra,
                [
                    'title' => $title,
                    'message' => $message,
                    'notify' => $notify ? 1 : 0,
                ]
            )
        ]);

        $headers = [
            'Authorization: key=' . $apiAccessKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gateway);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        // Execute the request
        $data = curl_exec($ch);

        // Close the connection to the server
        curl_close($ch);

        $data = json_decode($data, true);
        if (!is_array($data)) {
            static::getLogger()->error('Payload is not JSON', [
                'payload' => $data,
            ]);
            return 0;
        }

        if ($data['failure'] > 0) {
            for ($i = 0, $iMax = count($data['results']); $i < $iMax; $i++) {
                $result = $data['results'][$i];
                if (!isset($result['error'])) {
                    continue;
                }
                if ($result['error'] === 'NotRegistered') {
                    // Unregister the device since the token is known to be outdated/invalid
                    $this->unregisterDevice($deviceTokens[$i]);
                }
            }
        }

        return $data['success'];
    }

    /**
     * Sends a notification to an iOS device.
     *
     * @param int $notificationId
     * @param string $deviceToken
     * @param string $title
     * @param string $message
     * @param bool $sound
     * @param int $badge
     * @param array $extra
     * @param bool $immediate
     * @param int $isProductionToken
     * @return int Number of notification sent (1 if success, otherwise 0)
     * @throws InvalidCertificateException
     * @throws InvalidCertificatePassphraseException
     * @throws InvalidGatewayException
     */
    protected function notifyiOS(
        int    $notificationId,
        string $deviceToken,
        string $title,
        string $message,
        bool   $sound,
        int    $badge,
        array  $extra = [],
        bool   $immediate,
        bool   $isProductionToken = true
    ): int
    {
        $version = curl_version();
        if ($version['features'] & constant('CURL_VERSION_HTTP2') === 0) {
            // No support for HTTP/2, cannot continue
            static::getLogger()->warning('cURL does not support HTTP/2, cannot notify Apple devices');
            return 0;
        }

        // Path to the .p8 file downloaded from apple
        // see: https://developer.apple.com/documentation/usernotifications/setting_up_a_remote_notification_server/establishing_a_token-based_connection_to_apns#2943371
        $authKey = $this->getSettings()['iOS_p8_certificate'] ?? null;

        if (empty($authKey) || !is_readable($authKey)) {
            static::getLogger()->error('Invalid key file', [
                'iOS_p8_certificate' => $authKey,
            ]);
            throw new InvalidCertificateException();
        }

        // Team ID (From the Membership section of the ios developer website)
        // see: https://developer.apple.com/account/
        $teamId = $this->getSettings()['iOS_team_id'] ?? null;

        // The "Key ID" that is provided when you generate a new key
        $tokenId = $this->getSettings()['iOS_key_id'] ?? null;

        // The Bundle ID of the app you want to send the notification to
        $bundleId = $this->getSettings()['iOS_bundle_id'] ?? null;

        $payload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body' => $message,
                ],
                'sound' => $sound ? 'default' : '',
                'badge' => $badge,
            ]
        ];
        if (!empty($extra)) {
            $payload['aps']['data'] = $extra;
        }

        $tokenKey = file_get_contents($authKey);

        // See https://jwt.io/
        $jwtHeader = ['alg' => 'ES256', 'kid' => $tokenId];
        $jwtPayload = ['iss' => $teamId, 'iat' => time()];

        $rawTokenData = self::b64($jwtHeader, true) . '.' . self::b64($jwtPayload, true);
        $signature = '';
        openssl_sign($rawTokenData, $signature, $tokenKey, 'SHA256');
        $jwt = $rawTokenData . '.' . self::b64($signature);

        if ($isProductionToken) {
            // Production
            $gateway = 'https://api.push.apple.com/';
        } else {
            // Sandbox/Development
            $gateway = 'https://api.sandbox.push.apple.com/';
        }

        $ch = curl_init($gateway . '3/device/' . $deviceToken);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'content-type: application/json',
            "authorization: bearer $jwt",
            "apns-topic: $bundleId",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        static::getLogger()->debug('Notification for iOS', [
            'notification' => $notificationId,
            'token' => $deviceToken,
            'production' => $isProductionToken,
            'payload' => $payload,
            'response' => $response,
            'httpCode' => $httpCode,
        ]);

        // Close the connection to the server
        curl_close($ch);

        if ($httpCode === 400) {
            // BadDeviceToken
            $this->unregisterDevice($deviceToken, $isProductionToken);
        }

        return $httpCode === 200 ? 1 : 0;
    }

    /**
     * Returns the GCM access key.
     *
     * @return string|null
     */
    protected function getGCMAccessKey(): ?string
    {
        $settings = $this->getSettings();
        return $settings['gcm_access_key'] ?? null;
    }

    /**
     * Returns the global settings.
     *
     * @return array
     */
    protected function getSettings(): array
    {
        static $settings = null;

        if ($settings === null) {
            $settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($this->extKey);
        }

        return $settings ?? [];
    }

    /**
     * Returns a logger.
     *
     * @return \TYPO3\CMS\Core\Log\Logger
     */
    protected static function getLogger()
    {
        /** @var \TYPO3\CMS\Core\Log\Logger $logger */
        static $logger = null;
        if ($logger === null) {
            $logger = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)->getLogger(__CLASS__);
        }
        return $logger;
    }

    protected static function b64($raw, bool $json = false): string
    {
        if ($json) $raw = json_encode($raw);
        return str_replace('=', '', strtr(base64_encode($raw), '+/', '-_'));
    }
}
