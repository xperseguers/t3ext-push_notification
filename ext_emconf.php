<?php

// ---------------------------------------------------------------------
// Extension Manager/Repository config file for ext: "push_notification"
// ---------------------------------------------------------------------

$EM_CONF[$_EXTKEY] = [
    'title' => 'Push Notification Service for iOS and Android',
    'description' => 'Service to let extension developer send notifications to iOS and Android devices.',
    'category' => 'services',
    'version' => '1.1.0-dev',
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearcacheonload' => 0,
    'author' => 'Xavier Perseguers (Causal Sarl)',
    'author_email' => 'xavier@causal.ch',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-9.5.99',
            'php' => '7.2.0-7.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
