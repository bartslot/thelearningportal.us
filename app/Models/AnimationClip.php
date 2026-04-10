<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnimationClip extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'fbx_path', 'sort_order'];

    /** Returns the public URL for this clip's FBX file. */
    public function fbxUrl(): string
    {
        return asset($this->fbx_path);
    }
}
