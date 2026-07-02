<?php

declare(strict_types=1);

use Enthusiast\OrderPool\SyncCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'op:sync' => SyncCommand::class,
        ],
    ],
];
