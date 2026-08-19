<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'password#show', 'url' => '/password', 'verb' => 'GET'],
		['name' => 'password#update', 'url' => '/password', 'verb' => 'POST'],
	],
];
