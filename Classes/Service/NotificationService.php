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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Notification service.
 *
 * @category    Service
 * @package     TYPO3
 * @subpackage  tx_pushnotification
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class NotificationService implements \TYPO3\CMS\Core\SingletonInterface
{

    private $extKey = 'push_notification';

    /**
     * @var string
     */
    protected $largeIcon = '';

    /**
     * @var string
     */
    protected $smallIcon = '';

    /**
     * Returns a singleton of this class.
     *
     * @return NotificationService
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
     * Notifies a given user on all their registered devices.
     *
     * @param int $notificationId
     * @param int $userId
     * @param string $message
     * @param string $sound
     * @param int $badge
     * @param bool $production
     * @return int Number of notification sent (-1 if no notification needed)
     */
    public function notify ($notificationId, $userId, $message, $sound = 'default', $badge = 0, $production = true)
    {
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows('token', 'tx_pushnotification_tokens', 'user_id=' . (int)$userId);
        if (empty($rows)) {
            // No need to notify
            return -1;
        }

        $count = 0;
        $googleDeviceTokens = [];

        foreach ($rows as $row) {
            // Normalize the token
            $token = str_replace(' ', '', $row['token']);
            if (strlen($token) === 64) {
                // iOS
                $count += $this->notifyiOS($notificationId, $token, $message, $sound, $badge, true, $production);
            } else {
                // Android
                $googleDeviceTokens[] = $token;
            }
        }

        if (!empty($googleDeviceTokens)) {
            // GCM lets us send a message to multiple devices at once
            $title = '';
            $subtitle = '';
            $tickerText = '';
            $count += $this->notifyGCM($googleDeviceTokens, $message, $title, $subtitle, $tickerText, $sound, $sound);
        }

        return $count;
    }

    /**
     * Returns the large icon (GCM).
     *
     * @return string
     */
    public function getLargeIcon()
    {
        return $this->largeIcon;
    }

    /**
     * Sets the large icon (GCM).
     *
     * @param string $largeIcon
     * @return $this
     */
    public function setLargeIcon($largeIcon)
    {
        $this->largeIcon = $largeIcon;
        return $this;
    }

    /**
     * Returns the small icon (GCM).
     *
     * @return string
     */
    public function getSmallIcon()
    {
        return $this->smallIcon;
    }

    /**
     * Sets the small icon (GCM).
     *
     * @param string $smallIcon
     * @return $this
     */
    public function setSmallIcon($smallIcon)
    {
        $this->smallIcon = $smallIcon;
        return $this;
    }

    /**
     * Sends a notification to an iOS device.
     *
     * @param int $notificationId
     * @param string $deviceToken
     * @param string $message
     * @param string $sound
     * @param string $badge
     * @param bool $immediate
     * @param bool $production
     * @return int Nunber of notification sent (1 if success, otherwise 0)
     * @throws InvalidCertificateException
     * @throws InvalidGatewayException
     */
    protected function notifyiOS($notificationId, $deviceToken, $message, $sound, $badge, $immediate, $production) {
        $certificate = $this->getiOSCertificateFileName();
        if (empty($certificate) || !is_readable($certificate)) {
            throw new InvalidCertificateException();
        }

        $certificatePassPhrase = $this->getiOSCertificatePassPhrase();

        $payload = json_encode([
            'aps' => [
                'alert' => $message,
                'sound' => $sound,
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


        if ($production) {
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
            throw new InvalidGatewayException($gateway);
        }

        // Ensure that blocking is disabled
        stream_set_blocking($fp, 0);

        // Send it to the server
        $result = fwrite($fp, $notification, strlen($notification));

        // Close the connection to the server
        fclose($fp);

        return (bool)$result ? 1 : 0;
    }

    /**
     * Sends a notification using GCM.
     *
     * @param array|string $deviceTokens
     * @param string $message
     * @param string $title
     * @param string $subtitle
     * @param string $tickerText
     * @param bool $sound
     * @param bool $vibrate
     * @return int Number of notification sent
     * @throws InvalidGatewayException
     * @throws InvalidApiKeyException
     */
    protected function notifyGCM($deviceTokens, $message, $title, $subtitle, $tickerText, $sound, $vibrate)
    {
        if (!is_array($deviceTokens)) {
            $deviceTokens = [$deviceTokens];
        }

        $apiAccessKey = $this->getGCMAccessKey();
        if (strlen($apiAccessKey) < 8) {
            throw new InvalidApiKeyException();
        }

        $gateway = 'https://android.googleapis.com/gcm/send';

        $payload = json_encode([
            'registration_ids' => $deviceTokens,
            'data' => [
                'message' => $message,
                'title' => $title,
                'subtitle' => $subtitle,
                'tickerText' => $tickerText,
                'vibrate' => $vibrate ? 1 : 0,
                'sound' => $sound ? 1 : 0,
                'largeIcon' => $this->largeIcon,
                'smallIcon' => $this->smallIcon,
            ]
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
                        $this->unregisterDevice($deviceTokens[$i]);
                        break;
                }
            }
        }

        return $data['success'];
    }

    /**
     * Unregisters a device since the token is known to be outdated/invalid.
     *
     * @param string $token
     * @return void
     */
    protected function unregisterDevice($token)
    {
        $database = $this->getDatabaseConnection();
        $table = 'tx_pushnotification_tokens';

        $database->exec_DELETEquery(
            $table,
            'token=' . $database->fullQuoteStr($token, $table)
        );
    }

    /**
     * Returns the name of the .pem certificate (containing private + public keys) to use.
     *
     * @return string|null
     */
    protected function getiOSCertificateFileName()
    {
        $settings = $this->getSettings();
        return isset($settings['iOS_certificate']) ? $settings['iOS_certificate'] : null;
    }

    /**
     * Returns the pass phrase to use to open the certificate.
     *
     * @return string|null
     */
    protected function getiOSCertificatePassPhrase()
    {
        $settings = $this->getSettings();
        return isset($settings['iOS_certificate_passphrase']) ? $settings['iOS_certificate_passphrase'] : null;
    }

    /**
     * Returns the GCM access key.
     *
     * @return string|null
     */
    protected function getGCMAccessKey()
    {
        $settings = $this->getSettings();
        return isset($settings['gcm_access_key']) ? $settings['gcm_access_key'] : null;
    }

    /**
     * Returns the global settings.
     *
     * @return array
     */
    protected function getSettings()
    {
        static $settings = null;

        if ($settings === null) {
            $settings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        return $settings;
    }

    /**
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

}
