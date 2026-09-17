<?php

namespace Quansitech\Cmf\Import\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class FixtureMember extends Model
{
    protected $fillable = ['name', 'id_card', 'gender'];
}
