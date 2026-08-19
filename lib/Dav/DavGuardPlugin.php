<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Dav;

use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** @template-implements IEventListener<SabrePluginEvent> */
final class DavGuardPlugin extends ServerPlugin implements IEventListener {
	public function __construct(
		private IUserSession $session,
		private IUserConfig $config,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if ($event instanceof SabrePluginEvent && $event->getServer() !== null) {
			$event->getServer()->addPlugin($this);
		}
	}

	#[\Override]
	public function initialize(Server $server): void {
		$server->on('beforeMethod:*', [$this, 'beforeMethod'], 15);
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response): void {
		$user = $this->session->getUser();
		if ($user !== null && $this->config->getValueString($user->getUID(), Application::APP_ID, Application::FLAG_KEY, '0') === '1') {
			throw new NotAuthenticated('Password change required');
		}
	}
}
