<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Middleware;

use Exception;
use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Controller\PasswordController;
use OCA\CloudCIXOnboarding\Exception\PasswordChangeRequiredException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class PasswordChangeMiddleware extends Middleware {
	public function __construct(
		private IUserSession $session,
		private IUserConfig $config,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function beforeController(Controller $controller, string $methodName): void {
		$user = $this->session->getUser();
		if ($user === null || $this->config->getValueString($user->getUID(), Application::APP_ID, Application::FLAG_KEY, '0') !== '1') {
			return;
		}

		if ($controller instanceof PasswordController || $this->isAllowedInfrastructureRequest()) {
			return;
		}

		throw new PasswordChangeRequiredException();
	}

	#[\Override]
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof PasswordChangeRequiredException) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('cloudcix_onboarding.password.show'));
		}

		throw $exception;
	}

	private function isAllowedInfrastructureRequest(): bool {
		$method = $this->request->getMethod();
		$path = $this->request->getPathInfo();
		if ($method === 'GET' && $path === '/logout') {
			return true;
		}

		return in_array($method, ['GET', 'HEAD'], true)
			&& is_string($path)
			&& (str_starts_with($path, '/css/') || str_starts_with($path, '/js/'));
	}
}
