<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'PGU Brofix Cloudflare Helper',
    'description' => 'Extends sypets/brofix to improve detection and display of Cloudflare-protected links.',
    'category' => 'module',
    'author' => 'Patrice Gaumond, Jules Agent',
    'author_email' => 'patrice.gaumond@pgu.dev',
    'state' => 'alpha',
    'clearCacheOnLoad' => 1, // Recommended to clear cache on load for such modifications
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.9.99',
            'brofix' => '', // Dependency on sypets/brofix extension (version can be specified if known)
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'Gaumondp\\PguBrofixExtras\\' => 'Classes/',
        ],
    ],
];
