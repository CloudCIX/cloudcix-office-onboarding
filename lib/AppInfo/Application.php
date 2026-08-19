<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\AppInfo;

use OCA\CloudCIXOnboarding\Listener\DirectLoginGuardListener;
use OCA\CloudCIXOnboarding\Listener\PasswordUpdatedListener;
use OCA\CloudCIXOnboarding\Middleware\PasswordChangeMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\BeforeUserLoggedInEvent;
use OCP\User\Events\PasswordUpdatedEvent;

final class Application extends App implements IBootstrap {
	public const APP_ID = 'cloudcix_onboarding';
	public const FLAG_KEY = 'must_change_password';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(PasswordChangeMiddleware::class, true);
		$context->registerEventListener(BeforeUserLoggedInEvent::class, DirectLoginGuardListener::class);
		$context->registerEventListener(PasswordUpdatedEvent::class, PasswordUpdatedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
