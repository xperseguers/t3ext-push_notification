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
 * This class provides a purge outdated iOS device tokens.
 *
 * @category    Service
 * @package     TYPO3
 * @subpackage  tx_pushnotification
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class AppleFeedbackTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask
{
    /**
     * Method executed from the Scheduler.
     *
     * @return boolean
     */
    public function execute()
    {
        $start = $GLOBALS['EXEC_TIME'];

        $success = $this->purgeOutdatedTokens();

        if ($success) {
            $this->lastRun = $start;
            $this->save();
        }

        return $success;
    }

    /**
     * Purges outdated iOS device tokens.
     *
     * @return bool
     */
    protected function purgeOutdatedTokens()
    {
        $notificationService = \Causal\PushNotification\Service\NotificationService::getInstance();

        $isProduction = strpos(GeneralUtility::getIndpEnv('TYPO3_HOST_ONLY'), '.local') === false;
        $notificationService->setIsProduction($isProduction);

        // Actual processing
        $notificationService->processFeedbackiOS();

        return true;
    }

}
