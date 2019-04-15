<?php

// ---------------------------------------------------------------------
// Extension Manager/Repository config file for ext: "push_notification"
// ---------------------------------------------------------------------

$EM_CONF[$_EXTKEY] = [
    'title' => 'Push Notification Service for iOS and Android',
    'description' => 'Service to let extension developer send notifications to iOS and Android devices.',
    'category' => 'services',
    'shy' => 0,
    'version' => '1.1.0-dev',
    'priority' => '',
    'loadOrder' => '',
    'module' => '',
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'modify_tables' => '',
    'clearcacheonload' => 0,
    'lockType' => '',
    'author' => 'Xavier Perseguers (Causal Sarl)',
    'author_email' => 'xavier@causal.ch',
    'CGLcompliance' => '',
    'CGLcompliance_note' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-9.5.99',
            'php' => '7.2.0-7.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    '_md5_values_when_last_written' => '',
];
