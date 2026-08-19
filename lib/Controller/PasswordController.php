<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Controller;

use OC\User\Session as TokenSession;
use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\StandaloneTemplateResponse;
use OCP\Config\IUserConfig;
use OCP\HintException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

final class PasswordController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session,
		private IUserManager $users,
		private IUserConfig $config,
		private IURLGenerator $urlGenerator,
		private TokenSession $tokenSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): StandaloneTemplateResponse|RedirectResponse {
		$user = $this->session->getUser();
		if ($user === null || !$this->isFlagged($user->getUID())) {
			return $this->filesRedirect();
		}

		return $this->form();
	}

	#[NoAdminRequired]
	#[BruteForceProtection(action: 'cloudcixOnboardingPassword')]
	public function update(
		string $currentPassword = '',
		string $newPassword = '',
		string $confirmPassword = '',
	): StandaloneTemplateResponse|RedirectResponse {
		$user = $this->session->getUser();
		if ($user === null || !$this->isFlagged($user->getUID())) {
			return $this->filesRedirect();
		}

		if ($newPassword === '') {
			return $this->form($this->l10n->t('New password must not be empty.'), Http::STATUS_BAD_REQUEST);
		}
		if ($newPassword !== $confirmPassword) {
			return $this->form($this->l10n->t('The new passwords do not match.'), Http::STATUS_BAD_REQUEST);
		}
		if (strlen($newPassword) > IUserManager::MAX_PASSWORD_LENGTH) {
			return $this->form($this->l10n->t('The new password is too long.'), Http::STATUS_BAD_REQUEST);
		}

		$authenticated = $this->users->checkPassword($user->getUID(), $currentPassword);
		if (!$authenticated instanceof IUser || $authenticated->getUID() !== $user->getUID()) {
			$response = $this->form($this->l10n->t('Current password is incorrect.'), Http::STATUS_FORBIDDEN);
			$response->throttle(['user' => $user->getUID()]);
			return $response;
		}
		if (hash_equals($currentPassword, $newPassword)) {
			return $this->form(
				$this->l10n->t('Choose a password different from your current password.'),
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			if (!$user->setPassword($newPassword)) {
				return $this->form($this->l10n->t('Unable to change password.'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (HintException $exception) {
			return $this->form($exception->getHint(), Http::STATUS_BAD_REQUEST);
		} catch (Throwable) {
			$this->logger->error('CloudCIX onboarding password update failed', ['user' => $user->getUID()]);
			return $this->form($this->l10n->t('Unable to change password.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->tokenSession->updateSessionTokenPassword($newPassword);
		return $this->filesRedirect();
	}

	private function isFlagged(string $uid): bool {
		return $this->config->getValueString($uid, Application::APP_ID, Application::FLAG_KEY, '0') === '1';
	}

	private function filesRedirect(): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index', []));
	}

	private function form(?string $error = null, int $status = Http::STATUS_OK): StandaloneTemplateResponse {
		$response = new StandaloneTemplateResponse(
			Application::APP_ID,
			'change-password',
			[
				'error' => $error,
				'submitUrl' => $this->urlGenerator->linkToRoute('cloudcix_onboarding.password.update', []),
				'logoutUrl' => $this->urlGenerator->linkToRoute('core.login.logout', []),
			],
			StandaloneTemplateResponse::RENDER_AS_GUEST,
			$status,
		);
		$response->cacheFor(0);
		return $response;
	}
}
