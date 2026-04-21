<?php

declare(strict_types=1);

namespace CodexAuthProxy\Usage;

use CodexAuthProxy\Account\CodexAccount;

interface UsageClient
{
    public function fetch(CodexAccount $account): AccountUsage;
}
