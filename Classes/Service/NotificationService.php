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
        foreach ($rows as $row) {
            // Normalize the token
            $token = str_replace(' ', '', $row['token']);
            if (strlen($token) === 64) {
                // iOS
                if ($this->notifyiOS($notificationId, $token, $message, $sound, $badge, true, $production)) {
                    $count++;
                }
            } else {
                // Android
                // TODO
            }
        }

        return $count;
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
     * @return bool
     */
    protected function notifyiOS($notificationId, $deviceToken, $message, $sound, $badge, $immediate, $production) {
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
        $certificate = $this->getiOSCertificateFileName();
        $certificatePassPhrase = $this->getiOSCertificatePassPhrase();
        $ctx = stream_context_create();
        if (!empty($certificate) && is_readable($certificate)) {
            stream_context_set_option($ctx, 'ssl', 'local_cert', $certificate);
        }
        if (!empty($certificatePassPhrase)) {
            stream_context_set_option($ctx, 'ssl', 'passphrase', $certificatePassPhrase);
        }

        // Open a connection to the APNS server
        $fp = stream_socket_client($gateway, $err, $errstr, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);
        if (!$fp) {
            // Fail to connect
            return false;
        }

        // Esnure that blocking is disabled
        stream_set_blocking($fp, 0);

        // Send it to the server
        $result = fwrite($fp, $notification, strlen($notification));

        // Close the connection to the server
        fclose($fp);

        return (bool)$result;
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
