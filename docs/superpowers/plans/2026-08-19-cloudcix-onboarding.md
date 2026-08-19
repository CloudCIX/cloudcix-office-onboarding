# CloudCIX Onboarding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Nextcloud 34 app that forces only the explicitly marked CloudCIX bootstrap administrator to change its password before using Nextcloud.

**Architecture:** A globally registered AppFramework middleware redirects flagged browser sessions, a login event listener rejects the bootstrap password on raw direct-auth endpoints, a server-rendered controller changes the password through `IUser`, and `PasswordUpdatedEvent` clears the flag. All state uses typed per-user configuration; one documented `OC\User\Session` call preserves the active browser token because Nextcloud 34 has no public equivalent.

**Tech Stack:** PHP 8.2+, Nextcloud 34 AppFramework/OCP APIs, PHPUnit in a Nextcloud server checkout, server-rendered PHP/CSS.

**Spec:** `docs/superpowers/specs/2026-08-19-cloudcix-onboarding-design.md`

## Global Constraints

- App ID is exactly `cloudcix_onboarding`; PHP namespace is `OCA\CloudCIXOnboarding`.
- Declare compatibility only with Nextcloud 34 (`min-version="34" max-version="34"`).
- Never access the database directly, hash passwords, log passwords, or store plaintext credentials.
- Use public Nextcloud APIs except the documented `OC\User\Session::updateSessionTokenPassword()` compatibility boundary.
- Keep GET page rendering CSRF-exempt; keep POST CSRF protection enabled by default.
- Add no JavaScript framework, database table, migration, background job, admin settings page, or new dependency.
- Mark only the account explicitly selected by `occ user:setting`; never infer the flag from admin or first-login state.

---

### Task 1: App metadata, routing, and test harness

**Files:**
- Create: `appinfo/info.xml`
- Create: `appinfo/routes.php`
- Create: `lib/AppInfo/Application.php`
- Create: `tests/bootstrap.php`
- Create: `tests/phpunit.xml`
- Create: `LICENSE`

**Interfaces:**
- Produces: `Application::APP_ID`, `Application::FLAG_KEY`, GET route `cloudcix_onboarding.password.show`, POST route `cloudcix_onboarding.password.update`.
- Consumes: Nextcloud 34 bootstrap APIs and the listener/middleware classes created by later tasks.

- [ ] **Step 1: Add metadata and routes**

Create `appinfo/info.xml`:

```xml
<?xml version="1.0"?>
<info xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
      xsi:noNamespaceSchemaLocation="https://apps.nextcloud.com/schema/apps/info.xsd">
	<id>cloudcix_onboarding</id>
	<name>CloudCIX Onboarding</name>
	<summary>Require the provisioned administrator to replace its initial password</summary>
	<description>Forces an explicitly marked CloudCIX Office user to change its bootstrap password.</description>
	<version>1.0.0</version>
	<licence>agpl</licence>
	<author>CloudCIX</author>
	<namespace>CloudCIXOnboarding</namespace>
	<category>security</category>
	<dependencies>
		<nextcloud min-version="34" max-version="34"/>
	</dependencies>
</info>
```

Create `appinfo/routes.php`:

```php
<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'password#show', 'url' => '/password', 'verb' => 'GET'],
		['name' => 'password#update', 'url' => '/password', 'verb' => 'POST'],
	],
];
```

- [ ] **Step 2: Add bootstrap registration**

Create `Application` with constants and registrations:

```php
final class Application extends App implements IBootstrap {
	public const APP_ID = 'cloudcix_onboarding';
	public const FLAG_KEY = 'must_change_password';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(PasswordChangeMiddleware::class, true);
		$context->registerEventListener(BeforeUserLoggedInEvent::class, DirectLoginGuardListener::class);
		$context->registerEventListener(PasswordUpdatedEvent::class, PasswordUpdatedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
```

- [ ] **Step 3: Add the Nextcloud-native PHPUnit harness**

Create `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../lib/base.php';
\OC_App::loadApp('cloudcix_onboarding');
\OC_Hook::clear();
```

Create `tests/phpunit.xml` using `./bootstrap.php`, a testsuite directory of `.`, suffix `Test.php`, PHPUnit 10.5 schema, and source include directory `../lib`. Do not add Composer dependencies; tests run with Nextcloud 34's test environment.

- [ ] **Step 4: Add the license notice**

Do not modify the existing `.gitignore`; it contains unrelated user changes. Add this concise `LICENSE` notice:

```text
CloudCIX Onboarding
Copyright (C) 2026 CloudCIX

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or any later version.

https://www.gnu.org/licenses/agpl-3.0.html
```

- [ ] **Step 5: Validate configuration files**

Run:

```bash
xmllint --noout appinfo/info.xml tests/phpunit.xml
git diff --check -- appinfo lib tests LICENSE
```

Expected: both XML files parse and `git diff --check` emits no output.

- [ ] **Step 6: Commit the foundation**

```bash
git add appinfo lib/AppInfo tests/bootstrap.php tests/phpunit.xml LICENSE
git commit -m "build: scaffold Nextcloud onboarding app"
```

---

### Task 2: Password-event state clearing and direct-auth guard

**Files:**
- Create: `lib/Listener/PasswordUpdatedListener.php`
- Create: `lib/Listener/DirectLoginGuardListener.php`
- Create: `tests/Listener/PasswordUpdatedListenerTest.php`
- Create: `tests/Listener/DirectLoginGuardListenerTest.php`

**Interfaces:**
- Consumes: `Application::APP_ID`, `Application::FLAG_KEY`, `IUserConfig`, `IUserManager`, `IRequest`.
- Produces: `PasswordUpdatedListener::handle(Event): void`; `DirectLoginGuardListener::handle(Event): void`.

- [ ] **Step 1: Write failing password-updated listener tests**

Cover exactly these behaviors:

```php
public function testClearsFlagForFlaggedUser(): void {
	$user = $this->createMock(IUser::class);
	$user->method('getUID')->willReturn('admin');
	$this->config->method('getValueString')->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')->willReturn('1');
	$this->config->expects(self::once())->method('deleteUserConfig')->with('admin', Application::APP_ID, Application::FLAG_KEY);
	$this->listener->handle(new PasswordUpdatedEvent($user, 'not-inspected'));
}

public function testIgnoresAbsentOrUnflaggedSetting(): void {
	$user = $this->createMock(IUser::class);
	$user->method('getUID')->willReturn('admin');
	$this->config->method('getValueString')->with('admin', Application::APP_ID, Application::FLAG_KEY, '0')->willReturn('0');
	$this->config->expects(self::never())->method('deleteUserConfig');
	$this->listener->handle(new PasswordUpdatedEvent($user, 'not-inspected'));
}

public function testIgnoresOtherEventTypes(): void {
	$this->config->expects(self::never())->method('getValueString');
	$this->config->expects(self::never())->method('deleteUserConfig');
	$this->listener->handle(new Event());
}
```

- [ ] **Step 2: Run the listener test and verify RED**

Run from a Nextcloud 34 checkout with this repository mounted/symlinked at `custom_apps/cloudcix_onboarding`:

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Listener/PasswordUpdatedListenerTest.php
```

Expected: failure because `PasswordUpdatedListener` does not exist.

- [ ] **Step 3: Implement the minimal password-updated listener**

```php
final class PasswordUpdatedListener implements IEventListener {
	public function __construct(private IUserConfig $config) {
	}

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
```

Never call `PasswordUpdatedEvent::getPassword()`.

- [ ] **Step 4: Run the listener test and verify GREEN**

Run the Step 2 command. Expected: all listener cases pass.

- [ ] **Step 5: Write failing direct-login guard tests**

Construct `BeforeUserLoggedInEvent('admin', 'secret')` and cover:

- unknown user: allowed;
- unflagged user: allowed;
- flagged user on `POST /login`: allowed so the browser session can start;
- flagged user on `/remote.php/dav/files/admin`: throws `OCP\HintException` with a generic password-change-required hint;
- the submitted password never appears in the exception message.

- [ ] **Step 6: Run the direct-login test and verify RED**

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Listener/DirectLoginGuardListenerTest.php
```

Expected: failure because `DirectLoginGuardListener` does not exist.

- [ ] **Step 7: Implement the direct-login guard**

```php
final class DirectLoginGuardListener implements IEventListener {
	public function __construct(
		private IUserManager $users,
		private IUserConfig $config,
		private IRequest $request,
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeUserLoggedInEvent || $this->request->getPathInfo() === '/login') {
			return;
		}

		$user = $this->users->get($event->getUsername());
		if ($user !== null && $this->config->getValueString($user->getUID(), Application::APP_ID, Application::FLAG_KEY, '0') === '1') {
			throw new HintException(
				'Password change required',
				$this->l10n->t('Change your password in CloudCIX Office before using this service.'),
			);
		}
	}
}
```

The listener deliberately resolves a UID, not an email/login alias; the provisioned local administrator is marked and supplied by UID.

- [ ] **Step 8: Run both listener suites and commit**

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Listener
git add lib/Listener tests/Listener
git commit -m "feat: clear onboarding flag after password updates"
```

Expected: listener suite passes before commit.

---

### Task 3: Global forced-password middleware

**Files:**
- Create: `lib/Exception/PasswordChangeRequiredException.php`
- Create: `lib/Middleware/PasswordChangeMiddleware.php`
- Create: `tests/Middleware/PasswordChangeMiddlewareTest.php`

**Interfaces:**
- Consumes: `IUserSession::getUser()`, `IUserConfig::getValueString()`, `IRequest`, `IURLGenerator`, `PasswordController`.
- Produces: `PasswordChangeMiddleware::beforeController(Controller, string): void`; `afterException(Controller, string, Exception): Response`.

- [ ] **Step 1: Write failing middleware tests**

Build the middleware with mocks and cover:

```php
public function testAllowsAnonymousRequest(): void;
public function testAllowsUnflaggedUser(): void;
public function testRedirectsFlaggedUser(): void;
public function testAllowsFlaggedUserPasswordController(): void;
public function testAllowsFlaggedUserLogout(): void;
public function testAllowsFlaggedUserGeneratedCssAndJsGetRequests(): void;
public function testDoesNotAllowMutatingResourcePathRequest(): void;
public function testConvertsOnlyItsOwnExceptionToRedirect(): void;
public function testRethrowsUnrelatedException(): void;
```

For the redirect case, assert that `beforeController()` throws `PasswordChangeRequiredException`, then assert `afterException()` returns a `RedirectResponse` whose URL equals `/index.php/apps/cloudcix_onboarding/password` from the mocked URL generator.

- [ ] **Step 2: Run the middleware test and verify RED**

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Middleware/PasswordChangeMiddlewareTest.php
```

Expected: failure because the middleware and exception do not exist.

- [ ] **Step 3: Implement the minimal exception and middleware**

The exception is an empty final subclass of `Exception`. The middleware's decision is:

```php
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

public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
	if ($exception instanceof PasswordChangeRequiredException) {
		return new RedirectResponse($this->urlGenerator->linkToRoute('cloudcix_onboarding.password.show'));
	}
	throw $exception;
}
```

- [ ] **Step 4: Run middleware tests and verify GREEN**

Run the Step 2 command. Expected: all middleware cases pass.

- [ ] **Step 5: Review the allowlist and commit**

Confirm there is no broad `/apps/`, `/ocs/`, `/remote.php`, or arbitrary GET exemption. Then:

```bash
git add lib/Exception lib/Middleware tests/Middleware
git commit -m "feat: force flagged users to onboarding"
```

---

### Task 4: Password controller, template, and styles

**Files:**
- Create: `lib/Controller/PasswordController.php`
- Create: `templates/change-password.php`
- Create: `css/change-password.css`
- Create: `tests/Controller/PasswordControllerTest.php`

**Interfaces:**
- Consumes: `IUserSession`, `IUserManager`, `IUserConfig`, `IURLGenerator`, `IL10N`, `Psr\Log\LoggerInterface`, internal `OC\User\Session::updateSessionTokenPassword()`.
- Produces: `PasswordController::show(): StandaloneTemplateResponse|RedirectResponse`; `update(string, string, string): StandaloneTemplateResponse|RedirectResponse`.

- [ ] **Step 1: Write failing GET controller tests**

Cover:

- authenticated flagged user receives `StandaloneTemplateResponse` for `change-password` with guest rendering;
- authenticated unflagged user receives a redirect to the mocked `files.view.index` URL;
- response parameters contain only submit/logout URLs and error text, never a password.

- [ ] **Step 2: Write failing POST validation tests**

Use literal sentinel passwords and cover one behavior per test:

```php
public function testRejectsEmptyNewPassword(): void;
public function testRejectsMismatchedPasswords(): void;
public function testRejectsOverlongPassword(): void;
public function testRejectsWrongCurrentPasswordAndThrottles(): void;
public function testRejectsSamePassword(): void;
public function testReturnsPasswordPolicyHint(): void;
public function testReturnsGenericBackendFailure(): void;
public function testSuccessfulChangeUpdatesSessionTokenAndRedirectsToFiles(): void;
public function testPasswordsAreNeverLogged(): void;
```

The success test expects `IUser::setPassword('new-secret')`, `OC\User\Session::updateSessionTokenPassword('new-secret')`, and redirect `/index.php/apps/files`. It does not expect the controller to delete the flag because the synchronous event listener owns that responsibility.

The logging test makes `setPassword()` throw `RuntimeException`; its logger callback asserts neither log message nor context contains the current, new, or confirmation sentinel.

- [ ] **Step 3: Run controller tests and verify RED**

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Controller/PasswordControllerTest.php
```

Expected: failure because `PasswordController` does not exist.

- [ ] **Step 4: Implement GET rendering**

Use these attributes and response construction:

```php
#[NoAdminRequired]
#[NoCSRFRequired]
public function show(): StandaloneTemplateResponse|RedirectResponse {
	$user = $this->session->getUser();
	if ($user === null || !$this->isFlagged($user->getUID())) {
		return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index'));
	}
	return $this->form();
}
```

`form(?string $error = null, int $status = Http::STATUS_OK)` adds `change-password.css` with `OCP\Util::addStyle()`, creates server-generated submit/logout URLs, returns a guest `StandaloneTemplateResponse`, and disables caching.

- [ ] **Step 5: Implement POST validation and password update**

Keep CSRF protection by omitting `NoCSRFRequired`:

```php
#[NoAdminRequired]
#[BruteForceProtection(action: 'cloudcixOnboardingPassword')]
public function update(string $currentPassword = '', string $newPassword = '', string $confirmPassword = ''): StandaloneTemplateResponse|RedirectResponse {
	$user = $this->session->getUser();
	if ($user === null || !$this->isFlagged($user->getUID())) {
		return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index'));
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
		return $this->form($this->l10n->t('Choose a password different from your current password.'), Http::STATUS_BAD_REQUEST);
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
	return new RedirectResponse($this->urlGenerator->linkToRoute('files.view.index'));
}
```

- [ ] **Step 6: Implement the accessible server-rendered template**

The form posts to `$_['submitUrl']`, includes:

```php
<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
<input id="current-password" name="currentPassword" type="password" autocomplete="current-password" required autofocus>
<input id="new-password" name="newPassword" type="password" autocomplete="new-password" required>
<input id="confirm-password" name="confirmPassword" type="password" autocomplete="new-password" required>
```

Use visible `<label>` elements, an `aria-live="polite"` error block, the exact requested heading/message/button text, and escaped output. Add only enough CSS for a centered Nextcloud-style card and readable form spacing.

- [ ] **Step 7: Run controller and full unit suites**

```bash
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml \
  custom_apps/cloudcix_onboarding/tests/Controller/PasswordControllerTest.php
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml
```

Expected: controller tests and complete app unit suite pass.

- [ ] **Step 8: Commit the password flow**

```bash
git add lib/Controller templates css tests/Controller
git commit -m "feat: add secure first-login password change"
```

---

### Task 5: Installation, bootstrap, and acceptance documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Documents: development bind mount, production bind mount on both services, exact bootstrap commands, internal API boundary, middleware behavior, tests, and manual acceptance.

- [ ] **Step 1: Write the complete README**

Document:

- purpose and Nextcloud 34 compatibility;
- file placement at `/opt/cloudcix-office/cloudcix_onboarding`;
- this exact Compose volume on both `nextcloud` and `cron`:

```yaml
- /opt/cloudcix-office/cloudcix_onboarding:/var/www/html/custom_apps/cloudcix_onboarding:ro
```

- why a plain custom image and `docker cp` are not recommended with the existing parent named volume;
- exact enable/mark/inspect/clear commands;
- insertion immediately after “Nextcloud ready” and before credential publication/marker creation;
- middleware, direct-auth guard, CSRF, `IUser::setPassword()`, password policy, event-based clearing, and the sole internal session method;
- PHPUnit invocation from a Nextcloud 34 test checkout;
- the complete twelve-step manual acceptance checklist from the request, plus WebDAV rejection before onboarding.

- [ ] **Step 2: Verify documented shell and Compose fragments manually**

Confirm the commands preserve quoting around `"$NEXTCLOUD_ADMIN_USER"`, use `docker exec -u www-data cloudcix-nextcloud php occ`, and mount the app into both containers.

- [ ] **Step 3: Run repository checks**

Run locally available checks:

```bash
xmllint --noout appinfo/info.xml tests/phpunit.xml
git diff --check -- appinfo lib templates css tests README.md LICENSE
```

Run PHP checks inside the actual Nextcloud 34 container or a test checkout:

```bash
find custom_apps/cloudcix_onboarding -name '*.php' -print0 | xargs -0 -n1 php -l
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml
```

Expected: XML valid, no whitespace errors, every PHP file reports no syntax errors, all tests pass.

- [ ] **Step 4: Commit documentation**

```bash
git add README.md
git commit -m "docs: add appliance onboarding instructions"
```

---

### Task 6: Final security and bypass verification

**Files:**
- Modify only files implicated by a verified finding.

**Interfaces:**
- Verifies the end-to-end security contract; produces no speculative abstractions.

- [ ] **Step 1: Inspect the final route and middleware surface**

Search all controller routes and allowlist branches:

```bash
rg -n "NoCSRFRequired|PublicPage|registerMiddleware|isAllowedInfrastructureRequest|PasswordChangeRequiredException|routes" appinfo lib
```

Confirm POST has no CSRF exemption, no route is public, middleware is global, and resource exemptions cannot mutate state.

- [ ] **Step 2: Inspect all password flows and logging**

```bash
rg -n "currentPassword|newPassword|confirmPassword|getPassword\(|logger|setPassword|updateSessionTokenPassword" lib templates tests
```

Confirm no password is included in logging, exceptions, response parameters, persistent configuration, or documentation examples with real credentials.

- [ ] **Step 3: Exercise the manual acceptance flow on the appliance**

Mount the app into both services, enable it, mark only the initial administrator, and execute the documented incognito-browser checklist. Before completing the form, verify Files redirects and a DAV request using the bootstrap password is rejected. After success, verify old-password rejection, new-password login, Files access, and absence of further redirects.

- [ ] **Step 4: Run final automated verification**

```bash
xmllint --noout appinfo/info.xml tests/phpunit.xml
find custom_apps/cloudcix_onboarding -name '*.php' -print0 | xargs -0 -n1 php -l
phpunit -c custom_apps/cloudcix_onboarding/tests/phpunit.xml
git diff --check -- appinfo lib templates css tests README.md LICENSE
git status --short
```

Expected: every check passes; status contains only intentionally uncommitted changes, ideally none.

- [ ] **Step 5: Commit only verified fixes, if any**

If the review found a real issue, add one focused failing test, verify RED, implement the smallest fix, verify GREEN, and commit it. If no issue exists, make no empty commit.
