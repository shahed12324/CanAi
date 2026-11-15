<?php

declare(strict_types=1);

namespace App\Outreach;

/** The provider said no, or could not be reached. The message never contains a token or the provider's raw reply. */
class OAuthFailure extends \RuntimeException {}
