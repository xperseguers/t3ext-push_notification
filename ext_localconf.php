<?php
defined('TYPO3_MODE') || die();

/*****************************************************
 * Scheduler Tasks
 *****************************************************/

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Causal\PushNotification\Service\AppleFeedbackTask::class] = [
    'extension' => $_EXTKEY,
    'title' => 'Remove oudated iOS tokens',
    'description' => 'Connects to Apple Push Notification Feedback Service and retrieve outdated device tokens.',
];
