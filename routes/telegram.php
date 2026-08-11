<?php

use SergiX44\Nutgram\Nutgram;

$bot->onCommand('start', function (Nutgram $bot): void {
    $bot->sendMessage((string) config('app.name'));
})->description('Open the client portal');
