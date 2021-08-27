<?php

defined('TYPO3_MODE') || die();

(static function (string $_EXTKEY) {
    /*****************************************************
     * Scheduler Tasks
     *****************************************************/

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Causal\PushNotification\Service\AppleFeedbackTask::class] = [
        'extension' => $_EXTKEY,
        'title' => 'Remove outdated iOS tokens',
        'description' => 'Connects to Apple Push Notification Feedback Service and retrieve outdated device tokens.',
    ];
})('push_notification');
