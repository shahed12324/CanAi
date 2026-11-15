<?php

declare(strict_types=1);

namespace App\Calendar;

/** $getMessage() is one of: auth (token refused / permission missing), api (anything else the provider said), bad_reply (an answer we cannot read). */
final class CalendarApiException extends \RuntimeException {}
