<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use MarioDevv\RedsysSubscriptions\Billable;

class User extends Model
{
    use Billable;

    protected $guarded = [];
}
