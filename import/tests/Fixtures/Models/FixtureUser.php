<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class FixtureUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
