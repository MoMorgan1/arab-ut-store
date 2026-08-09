<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

abstract class DomainModel extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory, HasPublicUlid;

    /** @var list<string> */
    protected $guarded = ['id'];
}
