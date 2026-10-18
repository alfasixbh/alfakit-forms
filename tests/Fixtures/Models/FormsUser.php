<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitForms\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

class FormsUser extends TenantUser
{
    protected $table = 'users';
}
