<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Dav;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;

final class DavSessionGuard {
	private const DAV_AUTHENTICATED = 'AUTHENTICATED_TO_DAV_BACKEND';
	private const SERVICES = ['dav', 'webdav', 'files', 'caldav', 'calendar', 'carddav', 'contacts'];

	public function __construct(
		private IRequest $request,
		private IUserSession $session,
		private IUserConfig $config,
		private ISession $nextcloudSession,
	) {
	}

	public function enforce(): void {
		$path = $this->request->getPathInfo();
		if (!str_ends_with($this->request->getScriptName(), '/remote.php') || !is_string($path)) {
			return;
		}

		$service = explode('/', ltrim($path, '/'), 2)[0];
		if (!in_array($service, self::SERVICES, true)) {
			return;
		}

		$user = $this->session->getUser();
		if ($user === null) {
			return;
		}

		if ($this->config->getValueString($user->getUID(), Application::APP_ID, Application::FLAG_KEY, '0') === '1') {
			$this->nextcloudSession->set(self::DAV_AUTHENTICATED, '');
		} elseif ($this->nextcloudSession->get(self::DAV_AUTHENTICATED) === '') {
			$this->nextcloudSession->remove(self::DAV_AUTHENTICATED);
		}
	}
}
