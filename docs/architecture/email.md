# Email identity and delivery boundary

Email has two distinct M2 meanings:

1. a verified authentication identity for passwordless Client Portal access;
2. a future communication channel for transactional delivery.

The email address is normalized before it becomes a verified `ClientChannelIdentity` with `channel=email`. A pre-existing unverified `clients.email` profile value is not enough to merge a Client. The passwordless flow stores only a hash of the one-time code, applies expiry/attempt/request limits, regenerates the Laravel session after success, and never writes the code to audit metadata or application logs.

Application code depends on `EmailVerificationCodeSender`, not SMTP, SES, Postmark, Resend, or another provider. The current Laravel adapter is used only for the authentication email. Future booking confirmations, reminders, reschedule/cancellation notices, post-session messages, and other transactional scenarios can use the same provider-neutral delivery boundary without changing Client identity semantics. The full notification preference/scenario engine is deferred.
