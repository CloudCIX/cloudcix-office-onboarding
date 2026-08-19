<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 CloudCIX
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CloudCIXOnboarding\Tests\Controller;

use OC\User\Session as TokenSession;
use OCA\CloudCIXOnboarding\AppInfo\Application;
use OCA\CloudCIXOnboarding\Controller\PasswordController;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PasswordControllerTest extends TestCase {
	public function testShowsPasswordPageForFlaggedUserWithoutPasswordParameters(): void {
		[$controller] = $this->controller('1');

		$response = $controller->show();

		self::assertInstanceOf(StandaloneTemplateResponse::class, $response);
		self::assertSame('change-password', $response->getTemplateName());
		self::assertSame('guest', $response->getRenderAs());
		self::assertSame([
			'error' => null,
			'submitUrl' => '/index.php/apps/cloudcix_onboarding/password',
			'logoutUrl' => '/logout',
		], $response->getParams());
	}

	public function testRedirectsUnflaggedUserToFiles(): void {
		[$controller] = $this->controller('0');

		$response = $controller->show();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/index.php/apps/files', $response->getRedirectURL());
	}

	public function testRedirectsAnonymousUserToFiles(): void {
		[$controller] = $this->controller('0', false);

		$response = $controller->show();

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/index.php/apps/files', $response->getRedirectURL());
	}

	public function testRedirectsAnonymousPasswordUpdateToFiles(): void {
		[$controller, $user, $users] = $this->controller('0', false);
		$users->expects(self::never())->method('checkPassword');
		$user->expects(self::never())->method('setPassword');

		$response = $controller->update('current-secret', 'new-secret', 'new-secret');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/index.php/apps/files', $response->getRedirectURL());
	}

	public function testRejectsEmptyNewPassword(): void {
		[$controller, $user, $users] = $this->controller('1');
		$users->expects(self::never())->method('checkPassword');
		$user->expects(self::never())->method('setPassword');

		$response = $controller->update('current-secret', '', '');

		self::assertSame('New password must not be empty.', $response->getParams()['error']);
	}

	public function testRejectsMismatchedPasswords(): void {
		[$controller, $user] = $this->controller('1');
		$user->expects(self::never())->method('setPassword');

		$response = $controller->update('current-secret', 'new-secret', 'other-secret');

		self::assertSame('The new passwords do not match.', $response->getParams()['error']);
	}

	public function testRejectsOverlongPassword(): void {
		[$controller, $user] = $this->controller('1');
		$user->expects(self::never())->method('setPassword');
		$password = str_repeat('x', IUserManager::MAX_PASSWORD_LENGTH + 1);

		$response = $controller->update('current-secret', $password, $password);

		self::assertSame('The new password is too long.', $response->getParams()['error']);
	}

	public function testRejectsWrongCurrentPasswordAndThrottles(): void {
		[$controller, $user, $users] = $this->controller('1');
		$users->method('checkPassword')->with('admin', 'wrong-secret')->willReturn(false);
		$user->expects(self::never())->method('setPassword');

		$response = $controller->update('wrong-secret', 'new-secret', 'new-secret');

		self::assertSame('Current password is incorrect.', $response->getParams()['error']);
		self::assertTrue($response->isThrottled());
		self::assertSame(['user' => 'admin'], $response->getThrottleMetadata());
	}

	public function testRejectsSamePassword(): void {
		[$controller, $user, $users] = $this->controller('1');
		$users->method('checkPassword')->willReturn($user);
		$user->expects(self::never())->method('setPassword');

		$response = $controller->update('same-secret', 'same-secret', 'same-secret');

		self::assertSame('Choose a password different from your current password.', $response->getParams()['error']);
	}

	public function testReturnsConfiguredPasswordPolicyHint(): void {
		[$controller, $user, $users] = $this->controller('1');
		$users->method('checkPassword')->willReturn($user);
		$user->method('setPassword')->willThrowException(new HintException('policy', 'Use at least 15 characters.'));

		$response = $controller->update('current-secret', 'new-secret', 'new-secret');

		self::assertSame('Use at least 15 characters.', $response->getParams()['error']);
	}

	public function testSuccessfulChangeUpdatesTokenAndRedirectsToFiles(): void {
		[$controller, $user, $users, $tokenSession] = $this->controller('1');
		$users->method('checkPassword')->with('admin', 'current-secret')->willReturn($user);
		$user->expects(self::once())->method('setPassword')->with('new-secret')->willReturn(true);
		$tokenSession->expects(self::once())->method('updateSessionTokenPassword')->with('new-secret');

		$response = $controller->update('current-secret', 'new-secret', 'new-secret');

		self::assertInstanceOf(RedirectResponse::class, $response);
		self::assertSame('/index.php/apps/files', $response->getRedirectURL());
	}

	public function testReturnsGenericFailureWhenBackendRejectsPassword(): void {
		[$controller, $user, $users, $tokenSession] = $this->controller('1');
		$users->method('checkPassword')->willReturn($user);
		$user->method('setPassword')->willReturn(false);
		$tokenSession->expects(self::never())->method('updateSessionTokenPassword');

		$response = $controller->update('current-secret', 'new-secret', 'new-secret');

		self::assertSame('Unable to change password.', $response->getParams()['error']);
	}

	public function testUnexpectedFailureLogsNoPassword(): void {
		[$controller, $user, $users, $tokenSession, $logger] = $this->controller('1');
		$users->method('checkPassword')->willReturn($user);
		$user->method('setPassword')->willThrowException(new RuntimeException('backend failed'));
		$logger->expects(self::once())->method('error')->willReturnCallback(
			static function (string $message, array $context): void {
				$logged = $message . json_encode($context, JSON_THROW_ON_ERROR);
				self::assertStringNotContainsString('current-sentinel', $logged);
				self::assertStringNotContainsString('new-sentinel', $logged);
			}
		);

		$response = $controller->update('current-sentinel', 'new-sentinel', 'new-sentinel');

		self::assertSame('Unable to change password.', $response->getParams()['error']);
	}

	/**
	 * @return array{
	 *   PasswordController,
	 *   IUser&MockObject,
	 *   IUserManager&MockObject,
	 *   TokenSession&MockObject,
	 *   LoggerInterface&MockObject
	 * }
	 */
	private function controller(string $flag, bool $authenticated = true): array {
		$request = $this->createMock(IRequest::class);
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session->method('getUser')->willReturn($authenticated ? $user : null);
		$users = $this->createMock(IUserManager::class);
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueString')
			->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')
			->willReturn($flag);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnMap([
			['cloudcix_onboarding.password.update', [], '/index.php/apps/cloudcix_onboarding/password'],
			['core.login.logout', [], '/logout'],
			['files.view.index', [], '/index.php/apps/files'],
		]);
		$tokenSession = $this->createMock(TokenSession::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		return [
			new PasswordController(
				Application::APP_ID,
				$request,
				$session,
				$users,
				$config,
				$urlGenerator,
				$tokenSession,
				$l10n,
				$logger,
			),
			$user,
			$users,
			$tokenSession,
			$logger,
		];
	}
}
