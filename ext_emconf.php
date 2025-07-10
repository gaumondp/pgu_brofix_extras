<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PGU Brofix Extras',
    'description' => 'Enhanced Brofix link checker with Cloudflare detection and other improvements. Based on sypets/brofix.',
    'category' => 'module',
    'author' => 'Patrice Gaumond, Jules Agent',
    'author_email' => 'patrice.gaumond@pgu.dev',
    'state' => 'alpha',
    'clearCacheOnLoad' => 0,
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.9.99',
        ],
        'conflicts' => [
            'brofix' => '*', // Conflicts with the original brofix if installed
        ],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Gaumondp\\PguBrofixExtras\\' => 'Classes/',
        ],
    ],
];
