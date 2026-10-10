<?php

use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Application\AuthenticateClientWithEmailVerificationCode;
use App\Modules\Identity\Application\InvalidEmailAuthenticationCode;
use App\Modules\Identity\Application\RequestClientEmailVerificationCode;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RevokePrivilegedSessions;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

function emailSinkAcceptanceCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    smokeIdentity($userId, $clientId);
    $mailer = app('mail.manager')->mailer((string) config('mail.auth_mailer', config('mail.default')));
    $transport = $mailer->getSymfonyTransport();
    if (! $transport instanceof ArrayTransport) {
        fail('EMAIL SINK', 'configured transport is not a sink; no arbitrary external mailbox send');
    }
    $email = 'system-proof-20261009-'.Str::lower(Str::random(12)).'@example.test';
    app(RequestClientEmailVerificationCode::class)->handle($email);
    $sent = $transport->messages()->last();
    $message = $sent?->getOriginalMessage();
    $html = $message instanceof Email ? $message->getHtmlBody() : null;
    if (! is_string($html) || preg_match('/<strong>([0-9]{6})<\/strong>/', $html, $matches) !== 1) {
        fail('EMAIL SINK', 'verification code mail was not captured by the actual sink');
    }
    $authenticate = app(AuthenticateClientWithEmailVerificationCode::class);
    $client = $authenticate->handle($email, $matches[1]);
    try {
        $authenticate->handle($email, $matches[1]);
        fail('EMAIL SINK', 'consumed verification code was accepted a second time');
    } catch (InvalidEmailAuthenticationCode) {
        ok('EMAIL SINK', 'actual configured mail sink received one OTP; authentication succeeded; replay denied');
    }
    echo 'EMAIL_PROOF_SYNTHETIC_CLIENT_ID='.$client->getKey()."\n";
}

function companionAcceptanceCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, , $client] = smokeIdentity($userId, $clientId);
    if (! is_string($client->email) || ! preg_match('/^system-proof-20261009-[a-z0-9]{12}@example\.test$/', $client->email)) {
        fail('COMPANION ACCEPTANCE', 'only this task synthetic email-auth client may be used');
    }
    $accept = app(AcceptCompanionMessage::class);
    $key = 'system-proof:companion:20261009:'.$client->getKey();
    $turn = $accept->handle(
        client: $client,
        channel: 'portal',
        body: 'Привет, ты кто?',
        idempotencyKey: $key,
        originExternalId: 'portal:'.$key,
        locale: 'ru',
    );
    $replay = $accept->handle(
        client: $client,
        channel: 'portal',
        body: 'Привет, ты кто?',
        idempotencyKey: $key,
        originExternalId: 'portal:'.$key,
        locale: 'ru',
    );
    if ($turn->getKey() !== $replay->getKey()) {
        fail('COMPANION ACCEPTANCE', 'repeated accepted input created another turn');
    }
    echo 'COMPANION_PROOF_TURN_ID='.$turn->getKey()."\n";
    $deadline = microtime(true) + 45;
    do {
        $turn->refresh();
        if (! $turn->status->isActive()) {
            break;
        }
        usleep(500000);
    } while (microtime(true) < $deadline);
    $conversation = Conversation::query()
        ->where('organization_id', $organization->getKey())
        ->findOrFail($turn->conversation_id);
    echo 'COMPANION_ACCEPTANCE='.json_encode([
        'client_id' => $client->getKey(),
        'turn_id' => $turn->getKey(),
        'status' => $turn->status->value,
        'failure_code' => $turn->failure_code,
        'conversation_automation_state' => $conversation->automation_state->value,
        'same_turn_on_replay' => true,
        'queue_connection' => config('queue.default'),
    ], JSON_THROW_ON_ERROR)."\n";
    if ($turn->status->value !== 'completed') {
        fail('COMPANION ACCEPTANCE', 'real queued turn did not complete; inspect the safe failure code');
    }
    if ($conversation->automation_state->value !== 'ai_active') {
        fail('COMPANION ACCEPTANCE', 'ordinary greeting changed AI automation state');
    }
    $outbound = $turn->outboundMessage()->first();
    if ($outbound === null || $outbound->organization_id !== $organization->getKey()
        || $outbound->conversation_id !== $conversation->getKey()
        || trim(app(CompanionMessageBodyReader::class)->read($organization->getKey(), $outbound)) === '') {
        fail('COMPANION ACCEPTANCE', 'completed turn has no scoped nonempty outbound reply');
    }
    ok('COMPANION ACCEPTANCE', 'synthetic greeting through the real queue; no protected client data or Telegram send');
}

function browserFixtureCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, , $client] = smokeIdentity($userId, $clientId);
    if (! app()->environment('staging') || ! is_string($client->email)
        || ! preg_match('/^system-proof-20261009-[a-z0-9]{12}@example\.test$/', $client->email)) {
        fail('BROWSER FIXTURE', 'staging and this task synthetic client are required');
    }
    $mailer = app('mail.manager')->mailer((string) config('mail.auth_mailer', config('mail.default')));
    $transport = $mailer->getSymfonyTransport();
    if (! $transport instanceof ArrayTransport) {
        fail('BROWSER FIXTURE', 'a configured array sink is required');
    }
    app(RequestClientEmailVerificationCode::class)->handle($client->email);
    $html = $transport->messages()->last()?->getOriginalMessage()->getHtmlBody();
    if (! is_string($html) || preg_match('/<strong>([0-9]{6})<\/strong>/', $html, $matches) !== 1) {
        fail('BROWSER FIXTURE', 'sink OTP missing');
    }
    $password = Str::random(48);
    $user = DB::transaction(function () use ($organization, $password): User {
        $user = new User([
            'name' => 'Synthetic system proof operator',
            'email' => 'system-proof-browser-'.Str::lower(Str::random(12)).'@example.test',
            'password' => $password,
        ]);
        $user->save();
        $membership = new OrganizationMembership;
        $membership->forceFill([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrganizationRole::Administrator,
            'is_active' => true,
        ])->save();

        return $user;
    });
    echo json_encode([
        'app_url' => config('app.url'),
        'client_id' => $client->getKey(),
        'client_email' => $client->email,
        'client_code' => $matches[1],
        'user_id' => $user->getKey(),
        'user_email' => $user->email,
        'user_password' => $password,
    ], JSON_THROW_ON_ERROR)."\n";
}

function browserCodeCheck(int $userId, int $clientId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [, , $client] = smokeIdentity($userId, $clientId);
    if (! app()->environment('staging') || ! is_string($client->email)
        || ! preg_match('/^system-proof-20261009-[a-z0-9]{12}@example\.test$/', $client->email)) {
        fail('BROWSER CODE', 'only this task synthetic staging client is permitted');
    }
    $mailer = app('mail.manager')->mailer((string) config('mail.auth_mailer', config('mail.default')));
    $transport = $mailer->getSymfonyTransport();
    if (! $transport instanceof ArrayTransport) {
        fail('BROWSER CODE', 'configured mail sink required');
    }
    app(RequestClientEmailVerificationCode::class)->handle($client->email);
    $html = $transport->messages()->last()?->getOriginalMessage()->getHtmlBody();
    if (! is_string($html) || preg_match('/<strong>([0-9]{6})<\/strong>/', $html, $matches) !== 1) {
        fail('BROWSER CODE', 'OTP not captured');
    }
    echo json_encode(['code' => $matches[1]], JSON_THROW_ON_ERROR)."\n";
}

function browserCleanupCheck(int $userId, int $clientId, int $operatorId): void
{
    bootstrapApplication(false);
    requireStagingProofTarget();
    [$organization, $actor, $client] = smokeIdentity($userId, $clientId);
    if (! is_string($client->email) || ! preg_match('/^system-proof-20261009-[a-z0-9]{12}@example\.test$/', $client->email)) {
        fail('BROWSER CLEANUP', 'only this task synthetic client is permitted');
    }
    $operator = User::query()->whereKey($operatorId)->first();
    if (! $operator instanceof User || $operator->getKey() === $actor->getKey()
        || ! is_string($operator->email)
        || ! preg_match('/^system-proof-browser-[a-z0-9]{12}@example\.test$/', $operator->email)) {
        fail('BROWSER CLEANUP', 'operator is not the task synthetic browser fixture');
    }
    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $operator->getKey())
        ->where('is_active', true)
        ->first();
    if (! $membership instanceof OrganizationMembership) {
        fail('BROWSER CLEANUP', 'active synthetic browser membership is missing');
    }
    app(OrganizationContext::class)->set($organization);
    app(RevokePrivilegedSessions::class)->handle($operator);
    DB::transaction(function () use ($membership, $operator): void {
        $membership->forceFill(['is_active' => false])->save();
        DB::table('sessions')->where('user_id', $operator->getKey())->delete();
    });
    if ((bool) OrganizationMembership::query()->whereKey($membership->getKey())->value('is_active')) {
        fail('BROWSER CLEANUP', 'synthetic membership remains active');
    }
    ok('BROWSER CLEANUP', 'synthetic operator sessions revoked and membership deactivated');
}
