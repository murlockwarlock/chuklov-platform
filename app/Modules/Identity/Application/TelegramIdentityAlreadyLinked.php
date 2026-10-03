<?php

namespace App\Modules\Identity\Application;

use Illuminate\Auth\Access\AuthorizationException;

final class TelegramIdentityAlreadyLinked extends AuthorizationException {}
