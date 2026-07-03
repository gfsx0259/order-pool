<?php

declare(strict_types=1);

use Enthusiast\OrderPool\Debug\SimulateMatcherCommand;
use Enthusiast\OrderPool\SyncCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'op:sync' => SyncCommand::class,
            'order-pool:simulate' => SimulateMatcherCommand::class,
        ],
    ],
];
