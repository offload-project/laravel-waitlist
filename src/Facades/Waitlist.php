<?php

declare(strict_types=1);

namespace OffloadProject\Waitlist\Facades;

use Illuminate\Support\Facades\Facade;

final class Waitlist extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'waitlist';
    }
}
