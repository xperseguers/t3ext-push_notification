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

namespace Causal\PushNotification\Exception;

/**
 * Exception for invalid gateway.
 *
 * @category    Exception
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class InvalidGatewayException extends \Exception
{

    /**
     * @var string Error Message
     */
    protected $message = 'Cannot connect to gateway: ';

    /**
     * InvalidGatewayException constructor.
     *
     * @param string $gateway
     * @param string $message
     */
    public function __construct($gateway, $message = null)
    {
        if ($message !== null) {
            $this->message = $message;
        }
        parent::__construct($message . $gateway . '.', 1461613128);
    }
}
