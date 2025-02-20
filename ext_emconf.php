<?php

// ---------------------------------------------------------------------
// Extension Manager/Repository config file for ext: "push_notification"
// ---------------------------------------------------------------------

$EM_CONF[$_EXTKEY] = [
    'title' => 'Push Notification Service for iOS and Android',
    'description' => 'Service to let extension developer send notifications to iOS and Android devices.',
    'category' => 'services',
    'version' => '1.2.0-dev',
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearcacheonload' => 0,
    'author' => 'Xavier Perseguers (Causal Sarl)',
    'author_email' => 'xavier@causal.ch',
    'constraints' => [
        'depends' => [
            'php' => '7.4.0-8.3.99',
            'typo3' => '10.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
