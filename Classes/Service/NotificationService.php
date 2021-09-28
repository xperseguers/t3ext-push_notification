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
     * @var bool
     */
    protected $isProduction = true;

    /**
     * Returns a singleton of this class.
     *
     * @return NotificationService
     * @api
     */
    public static function getInstance()
    {
        static $instance = null;

        if ($instance === null) {
            $instance = GeneralUtility::makeInstance(__CLASS__);
        }

        return $instance;
    }

    /**
     * @return bool
     * @api
     */
    public function getIsProduction()
    {
        return $this->isProduction;
    }

    /**
     * @param bool $isProduction
     * @api
     */
    public function setIsProduction($isProduction)
    {
        $this->isProduction = $isProduction;
    }

    /**
     * Registers a device.
     *
     * @param string $token Straight token string as fetched from the mobile device
     * @param int $userId Your own internal user identifier to be used when notifying her/him
     * @param string $mode Either 'P' for production or 'D' for development
     * @api
     */
    public function registerDevice(string $token, int $userId, string $mode = 'P')
    {
        $this->registerDevices([[$token, $userId, $mode]]);
    }

    /**
     * Registers a set of devices.
     *
     * @param array $tokenUserIds Array of tuplets from token (position 0) and userId (position 1), possibly mode (position 2 where 'P' is production and 'D' development)
     * @api
     */
    public function registerDevices(array $tokenUserIds)
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
                ->query($query);
        }
    }

    /**
     * Unregisters a device.
     *
     * @param string $token
     * @api
     */
    public function unregisterDevice($token)
    {
        $this->unregisterDevices([$token]);
    }

    /**
     * Unregisters multiple devices.
     *
     * @param array $tokens
     * @api
     */
    public function unregisterDevices(array $tokens)
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

        $queryBuilder
            ->delete($table)
            ->where($queryBuilder->expr()->orX(... $conditions))
            ->execute();
    }

    /**
     * Drops stale tokens (older than 3 months)
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
    public function getTokens(int $userId)
    {
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_pushnotification_tokens')
            ->select(
                ['token'],
                'tx_pushnotification_tokens',
                [
                    'user_id' => $userId,
                ]
            )
            ->fetchAll();
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[] = $row['token'];
        }

        return $tokens;
    }

    /**
     * Notifies a given user on all their registered devices.
     *
     * @param int $notificationId
     * @param int $userId
     * @param string $title
     * @param string $message
     * @param bool $sound
     * @param int $badge
     * @param array $extra
     * @return int Number of notification sent (-1 if no notification needed)
     */
    public function notify(int $notificationId, int $userId, string $title, string $message, bool $sound = true, int $badge = 0, array $extra = []): int
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

        foreach ($tokens as $token) {
            // Normalize the token
            $token = str_replace(' ', '', $token);
            if (strlen($token) === 64) {
                // iOS
                $count += $this->notifyiOS($notificationId, $token, $message, $sound, $badge, true);
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
            for ($i = 0; $i < count($data['results']); $i++) {
                $result = $data['results'][$i];
                if (!isset($result['error'])) {
                    continue;
                }
                switch ($result['error']) {
                    case 'NotRegistered':
                        // Unregister the device since the token is known to be outdated/invalid
                        $this->unregisterDevice($deviceTokens[$i]);
                        break;
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
     * @param string $message
     * @param bool $sound
     * @param int $badge
     * @param bool $immediate
     * @return int Nunber of notification sent (1 if success, otherwise 0)
     * @throws InvalidCertificateException
     * @throws InvalidGatewayException
     */
    protected function notifyiOS(int $notificationId, string $deviceToken, string $message, bool $sound, int $badge, bool $immediate): int
    {
        $certificate = $this->getiOSCertificateFileName();
        if (empty($certificate) || !is_readable($certificate)) {
            static::getLogger()->error('Invalid certificate', [
                'certificate' => $certificate,
            ]);
            throw new InvalidCertificateException();
        }

        $certificatePassPhrase = $this->getiOSCertificatePassPhrase();

        $payload = json_encode([
            'aps' => [
                'alert' => $message,
                'sound' => $sound ? 'default' : '',
                'badge' => $badge,
            ]
        ]);

        $inner = ''
            // Id of 1
            . chr(1)
            // Length is always 32 bytes
            . pack('n', 32)
            // Hex string of the deviceToken
            . pack('H*', $deviceToken)

            // Id of 2
            . chr(2)
            // Length of the payload
            . pack('n', strlen($payload))
            . $payload

            // Id of 3
            . chr(3)
            // Length of integer is 4
            . pack('n', 4)
            // Pack notifier to length of 4
            . pack('N', $notificationId)

            // Id of 4
            . chr(4)
            // Length of integer is 4
            . pack('n', 4)
            // Set expiration to 1 day from now
            . pack('N', time() + 86400)

            // Id of 5
            . chr(5)
            // Length is 1
            . pack('n', 1)
            // Send immediately (10 = immediately, 5 = at a time that conserves the power on the device)
            . chr($immediate ? 10 : 5);

        $notification = ''
            // Id of 2
            . chr(2)
            // Length of the frame
            . pack('N', strlen($inner))
            // Frame
            . $inner;

        if ($this->isProduction) {
            $gateway = 'ssl://gateway.push.apple.com:2195';
        } else {
            $gateway = 'ssl://gateway.sandbox.push.apple.com:2195';
        }

        // Create a stream
        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $certificate);
        if (!empty($certificatePassPhrase)) {
            stream_context_set_option($ctx, 'ssl', 'passphrase', $certificatePassPhrase);
        }

        // Open a connection to the APNS server
        $fp = stream_socket_client($gateway, $err, $errstr, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);
        if (!$fp) {
            // Fail to connect
            static::getLogger()->error('Invalid gateway', [
                'gateway' => $gateway,
            ]);
            throw new InvalidGatewayException($gateway);
        }

        // Ensure that blocking is disabled
        stream_set_blocking($fp, 0);

        // Send it to the server
        fwrite($fp, $notification, strlen($notification));

        // Workaround to check if there were any errors during the last seconds of sending
        usleep(500000); // Pause for half a second

        // byte1=always 8, byte2=StatusCode, bytes3,4,5,6=identifier(notificationId)
        // Should return nothing if OK
        $appleErrorResponse = fread($fp, 6);
        $response = null;
        if ($appleErrorResponse) {
            // Unpack the error response (first byte "command" should always be 8)
            $response = unpack('Ccommand/Cstatus_code/Nidentifier', $appleErrorResponse);
        }

        static::getLogger()->debug('Notification for iOS', [
            'notification' => $notificationId,
            'token' => $deviceToken,
            'production' => $this->isProduction,
            'message' => $message,
            'response' => $response,
        ]);

        // Close the connection to the server
        fclose($fp);

        return $response === null ? 1 : 0;
    }

    /**
     * Processes the feedback from APNS (iOS).
     *
     * @throws InvalidCertificateException
     * @throws InvalidGatewayException
     */
    public function processFeedbackiOS()
    {
        $certificate = $this->getiOSCertificateFileName();
        if (empty($certificate) || !is_readable($certificate)) {
            throw new InvalidCertificateException();
        }

        $certificatePassPhrase = $this->getiOSCertificatePassPhrase();

        if ($this->isProduction) {
            $gateway = 'ssl://feedback.push.apple.com:2196';
        } else {
            $gateway = 'ssl://feedback.sandbox.push.apple.com:2196';
        }

        // Create a stream
        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $certificate);
        if (!empty($certificatePassPhrase)) {
            stream_context_set_option($ctx, 'ssl', 'passphrase', $certificatePassPhrase);
        }

        // Open a connection to the APNS feedback server
        $fp = stream_socket_client($gateway, $err, $errstr, 60, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            // Fail to connect
            throw new InvalidGatewayException($gateway);
        }

        $feedbackTokens = [];
        while (!feof($fp)) {
            $data = fread($fp, 38);
            if (strlen($data)) {
                $feedbackTokens[] = unpack('N1timestamp/n1length/H*devtoken', $data);
            }
        }

        // Close the connection to the server
        fclose($fp);

        // Unregisters the devices
        $tokens = [];
        foreach ($feedbackTokens as $token) {
            $tokens[] = $token['devtoken'];
        }
        $this->unregisterDevices($tokens);
    }

    /**
     * Returns the name of the .pem certificate (containing private + public keys) to use.
     *
     * @return string|null
     */
    protected function getiOSCertificateFileName(): ?string
    {
        $settings = $this->getSettings();
        return isset($settings['iOS_certificate']) ? $settings['iOS_certificate'] : null;
    }

    /**
     * Returns the pass phrase to use to open the certificate.
     *
     * @return string|null
     */
    protected function getiOSCertificatePassPhrase(): ?string
    {
        $settings = $this->getSettings();
        return isset($settings['iOS_certificate_passphrase']) ? $settings['iOS_certificate_passphrase'] : null;
    }

    /**
     * Returns the GCM access key.
     *
     * @return string|null
     */
    protected function getGCMAccessKey(): ?string
    {
        $settings = $this->getSettings();
        return isset($settings['gcm_access_key']) ? $settings['gcm_access_key'] : null;
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
            $typo3Branch = class_exists(\TYPO3\CMS\Core\Information\Typo3Version::class)
                ? (new \TYPO3\CMS\Core\Information\Typo3Version())->getBranch()
                : TYPO3_branch;
            if ((bool)version_compare($typo3Branch, '9.5', '<')) {
                $settings = $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey] ?? '';
                $settings = unserialize($settings, false) ?: [];
            } else {
                $settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get($this->extKey);
            }
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
}
