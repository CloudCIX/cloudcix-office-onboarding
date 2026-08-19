# CloudCIX Onboarding Design

## Purpose

`cloudcix_onboarding` is a deliberately small Nextcloud 34 app that forces only the CloudCIX-provisioned initial administrator to replace the bootstrap password before using the normal Nextcloud web UI.

CloudCIX marks that account explicitly. The app never infers the requirement from administrator status, first-login timestamps, or user creation, so users created later are unaffected.

## Supported platform

- Nextcloud `34.x` only; `appinfo/info.xml` declares minimum and maximum version `34`.
- PHP and public APIs shipped by Nextcloud 34.
- CloudCIX Office appliance using `nextcloud:34.0.0-apache`.
- PostgreSQL and Redis are unchanged; the app performs no direct database access.

The API review used the official Nextcloud `stable34` server source at commit `e74c4d2769f1677f4c5e134d82789420c65067cb`, the matching `stable34` developer manual, and the official `password_policy` `stable34` app.

## Per-user state

The bootstrap process sets this non-sensitive, non-lazy per-user value:

```text
app:   cloudcix_onboarding
key:   must_change_password
value: 1
```

Runtime code reads and removes it through `OCP\Config\IUserConfig`, the typed public user-configuration API available since Nextcloud 32. A user is flagged only when the stored string is exactly `1`; missing or unexpected values fail open to avoid trapping unrelated users.

The exact Nextcloud 34 command is:

```bash
php occ user:setting <uid> cloudcix_onboarding must_change_password 1
```

## Components

### Application registration

`OCA\CloudCIXOnboarding\AppInfo\Application` registers:

- `PasswordChangeMiddleware` globally with `registerMiddleware(..., true)`;
- `PasswordUpdatedListener` for `OCP\User\Events\PasswordUpdatedEvent`;
- `DirectLoginGuardListener` for `OCP\User\Events\BeforeUserLoggedInEvent`;
- `DavGuardPlugin` on Nextcloud 34's Sabre plugin-add event.

Global AppFramework middleware has been public since Nextcloud 26 and is documented for intercepting controllers belonging to other apps.

### Global middleware

Before each AppFramework controller call, the middleware:

1. Allows the request when no authenticated user exists.
2. Allows the request when the authenticated user is not flagged.
3. Allows the onboarding password controller.
4. Allows the core GET logout route.
5. Allows only GET/HEAD generated static-resource routes required by the standalone page, such as `/css/` and `/js/`; ordinary static files are served by Apache and never enter AppFramework middleware.
6. Throws an app-specific `PasswordChangeRequiredException` for every other flagged request.

`afterException()` converts only that exception into a `RedirectResponse` generated with `IURLGenerator`. All unrelated exceptions are rethrown unchanged.

The controller-instance exemption prevents a redirect loop without maintaining duplicated GET and POST URLs. The logout exemption gives a trapped or unexpected user a safe exit.

### Direct authentication guard

Global AppFramework middleware does not cover raw endpoints such as WebDAV. `DirectLoginGuardListener` therefore observes the public `BeforeUserLoggedInEvent` and rejects direct password authentication for a flagged account unless the request is the normal web login route. It resolves either the UID or the single email match that Nextcloud itself accepts; unknown and ambiguous email addresses remain rejected by Nextcloud.

This lets the browser establish the initial session, after which middleware forces the password page, while rejecting use of the bootstrap password directly against DAV or similar password-authenticated endpoints.

Nextcloud's DAV authenticator also accepts an established browser session cookie without emitting another login event. `DavGuardPlugin` runs after DAV authentication and rejects every request whose authenticated user is still flagged. This covers browser cookies, direct credentials, and DAV read or mutating methods without adding path or method exceptions.

The appliance must mark the account before distributing credentials and before provisioning any app password or client token. No app password exists in the specified first-boot flow. Revoking arbitrary pre-existing tokens is outside this app's scope.

### Password controller and page

The app exposes one GET and one POST route under its own app namespace.

The GET action:

- requires an authenticated user;
- permits non-admin users so an accidentally marked account is not trapped;
- disables CSRF checking only because it renders a non-mutating HTML page;
- redirects unflagged users to the Files route;
- returns a `StandaloneTemplateResponse` using the guest-style Nextcloud layout.

The PHP template contains the requested title, message, current-password, new-password, and confirmation inputs, CSRF token, error region, logout link, and submit button. A small app-owned CSS file supplies layout only. There is no JavaScript framework.

The POST action keeps Nextcloud's default CSRF protection and:

1. Requires an authenticated, currently flagged user.
2. Rejects missing fields.
3. Rejects a new password longer than `IUserManager::MAX_PASSWORD_LENGTH`.
4. Validates the current password with public `IUserManager::checkPassword()` and verifies the returned user's UID matches the session user's UID.
5. Throttles a wrong-current-password response using `BruteForceProtection` and `Response::throttle()`.
6. Requires new password and confirmation to match.
7. Rejects reuse of the supplied current password with `hash_equals()` after current-password authentication succeeds.
8. Calls public `IUser::setPassword()`.
9. Shows the translated hint from `OCP\HintException` when the configured password policy rejects the password.
10. Returns a generic error for other backend failures without logging password values or exception context that could capture arguments.
11. Updates the current browser session token and redirects to `files.view.index` through `IURLGenerator`.

Passwords exist only in request memory and are never persisted, emitted into templates, or logged.

### Session-token compatibility boundary

Nextcloud 34 exposes no public method for retrieving the current login alias or updating the encrypted password held by the active browser token after a password change. Its own Settings password controller uses `OC\User\Session::getLoginName()` and `updateSessionTokenPassword()`.

This app avoids the internal login-name method by validating the provisioned local account's UID through public `IUserManager::checkPassword()`. It uses `OC\User\Session::updateSessionTokenPassword()` as a documented compatibility boundary so the successful request can remain logged in and redirect to Files as required.

The public `OCP\SabrePluginEvent` is dispatched by the main Nextcloud 34 DAV server under the legacy `OCA\DAV\Connector\Sabre::addPlugin` event name. Registering the DAV guard on that name is a second fixed-version compatibility boundary.

Both compatibility boundaries must be reviewed before raising the app's declared maximum Nextcloud version. The public-only alternative to the session method is to log the user out after success.

### Flag clearing

`PasswordUpdatedListener` handles the public `PasswordUpdatedEvent`. The event is emitted by `IUser::setPassword()` only after the backend reports success.

The listener reads the event user's flag and deletes it only when its value is exactly `1`. Missing settings are ignored safely. The listener never reads `PasswordUpdatedEvent::getPassword()`.

Because event delivery is synchronous, the flag is removed before the controller redirects. Password changes performed through another supported Nextcloud mechanism also complete onboarding. Failed password changes emit no event and leave the flag intact.

## Error handling and security

- Authentication and admin checks use Nextcloud controller attributes; the app has no custom session or cookie mechanism.
- POST CSRF protection remains enabled by default.
- Wrong-current-password responses are generic and throttled.
- Policy hints are shown only after authentication and contain no submitted password.
- Redirect targets are generated server-side and accept no user-controlled destination.
- Middleware allows no general API or application route for a flagged user.
- The direct-login guard blocks the bootstrap password on raw password-authenticated endpoints.
- The DAV guard blocks flagged browser-cookie sessions and every DAV method after authentication.
- Static-resource exemptions are GET/HEAD-only and limited to resource paths, so they cannot mutate account data.
- Logout remains available.
- The app does not assume that every administrator or every first-login user is flagged.
- Unexpected middleware exceptions are not swallowed.
- Passwords, hashes, and event password payloads are never logged or stored by the app.

## Testing

Focused PHPUnit tests run inside a Nextcloud 34 server test environment.

Middleware tests cover anonymous access, unflagged access, redirecting a flagged user, allowing the password controller, allowing logout/resources, and exception-to-redirect handling without loops.

Direct-login guard tests cover unflagged authentication, allowed browser login, rejected flagged direct authentication, unique email aliases, and ambiguous email addresses. DAV plugin tests cover registration order plus anonymous, unflagged, and flagged sessions.

Controller tests cover unauthenticated and unflagged requests, wrong current password, mismatch, empty and same passwords, policy errors, backend failure, successful password update, session-token update, and Files redirect. Test doubles additionally assert that submitted passwords are never passed to the logger.

Listener tests cover unflagged users, flagged users, and absent settings.

Verification also includes PHP syntax checks, XML validation, and the documented manual incognito-browser acceptance test.

## Appliance packaging

Production stores the versioned app source at:

```text
/opt/cloudcix-office/cloudcix_onboarding
```

Both `nextcloud` and `cron` services bind-mount that directory read-only at:

```text
/var/www/html/custom_apps/cloudcix_onboarding
```

The services retain the shared `nextcloud_data:/var/www/html` volume. A nested read-only bind mount remains visible even though the parent path is a named volume, survives container recreation, and makes application updates an explicit host-package operation.

The production Compose addition on both services is:

```yaml
- /opt/cloudcix-office/cloudcix_onboarding:/var/www/html/custom_apps/cloudcix_onboarding:ro
```

A plain custom image that only copies the app under `/usr/src/nextcloud/custom_apps` is not sufficient for upgrades: the official Nextcloud Docker entrypoint copies that directory only when the persistent `/var/www/html/custom_apps` directory is empty. A custom image would therefore also need a synchronization entrypoint. That extra mechanism is unnecessary for this appliance.

`docker cp` is not a production installation method because it is not declared by Compose and can leave recreated or newly initialized volumes inconsistent.

## Bootstrap integration

After the existing `php occ status` readiness loop succeeds, and before credentials are published or `/var/lib/cloudcix-office/.provisioned` is created, bootstrap runs:

```bash
docker exec -u www-data cloudcix-nextcloud \
    php occ app:enable cloudcix_onboarding

docker exec -u www-data cloudcix-nextcloud \
    php occ user:setting \
    "$NEXTCLOUD_ADMIN_USER" \
    cloudcix_onboarding \
    must_change_password \
    1
```

The existing `set -euo pipefail` makes either failure abort provisioning. Re-running bootstrap before the provision marker is created is safe: enabling an enabled app and writing the same user setting are idempotent.

## Manual acceptance

1. Enable `cloudcix_onboarding`.
2. Mark the initial administrator with `must_change_password=1`.
3. Open Nextcloud in a private browser and log in with the current password; expect automatic redirection to the CloudCIX password page.
4. Navigate directly to Files; expect redirection back.
5. Submit the wrong current password; expect a clear error and no password change.
6. Submit the current password and a valid new password; expect success and a Files redirect.
7. Browse Nextcloud; expect no further forced redirects.
8. Log out.
9. Attempt login with the old password; expect rejection.
10. Log in with the new password; expect normal access without onboarding.
11. Before completing onboarding, attempt WebDAV authentication with the bootstrap password; expect rejection.
12. Before completing onboarding, retry DAV with the authenticated browser session cookie for both a read and a mutating method; expect rejection.

## Deliberate scope limits

- No UI or command for marking users beyond the standard `occ user:setting` command.
- No automatic first-login detection.
- No frontend framework, database table, migration, background job, admin settings page, or custom password policy.
- No support claim beyond Nextcloud 34 without a new API compatibility review.
- No management of pre-existing app tokens because a freshly provisioned initial administrator has none; token revocation can be added only if the appliance provisioning model changes.
