<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\IndexNow\Typo3\Http\KeyFileMiddleware;

return [
    'frontend' => [
        // Right after the site is known and before anything that costs: no
        // frontend session, no redirect lookup, no page resolution for a
        // request that is answered from a string comparison.
        'koh/indexnow/key-file' => [
            'target' => KeyFileMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/base-redirect-resolver',
                'typo3/cms-frontend/static-route-resolver',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
    ],
];
