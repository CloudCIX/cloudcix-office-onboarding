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
use OCP\HintException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\User\Events\BeforeUserLoggedInEvent;

/** @template-implements IEventListener<BeforeUserLoggedInEvent> */
final class DirectLoginGuardListener implements IEventListener {
	public function __construct(
		private IUserManager $users,
		private IUserConfig $config,
		private IRequest $request,
		private IL10N $l10n,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserLoggedInEvent || $this->request->getPathInfo() === '/login') {
			return;
		}

		$loginName = $event->getUsername();
		$user = $this->users->get($loginName);
		if ($user === null && filter_var($loginName, FILTER_VALIDATE_EMAIL) !== false) {
			$matches = $this->users->getByEmail($loginName);
			$user = count($matches) === 1 ? $matches[0] : null;
		}
		if ($user !== null && $this->config->getValueString($user->getUID(), Application::APP_ID, Application::FLAG_KEY, '0') === '1') {
			throw new HintException(
				'Password change required',
				$this->l10n->t('Change your password in CloudCIX Office before using this service.'),
			);
		}
	}
}
