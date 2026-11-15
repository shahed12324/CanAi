<?php

declare(strict_types=1);

namespace App\Outreach;

/** The user took access away (or the token expired for good). The account must be connected again. */
final class OAuthRevoked extends OAuthFailure {}
