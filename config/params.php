<?php

declare(strict_types=1);

use Enthusiast\OrderPool\Debug\SimulateMatcherCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'order-pool:simulate' => SimulateMatcherCommand::class,
        ],
    ],
];
