<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Listener;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PasswordUpdatedEvent;

/** @template-implements IEventListener<PasswordUpdatedEvent> */
final class PasswordUpdatedListener implements IEventListener {
	public function __construct(private IUserConfig $config) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof PasswordUpdatedEvent) {
			return;
		}

		$uid = $event->getUid();
		if ($this->config->getValueString($uid, Application::APP_ID, Application::FLAG_KEY, '0') === '1') {
			$this->config->deleteUserConfig($uid, Application::APP_ID, Application::FLAG_KEY);
		}
	}
}
