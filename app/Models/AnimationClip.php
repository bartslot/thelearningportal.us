<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnimationClip extends Model
{
    use HasFactory;

    protected $fillable = [
        'clip_id',
        'name',
        'category',
        'source',
        'fbx_path',
        'glb_path',
        'status',
        'conversion_error',
    ];

    /** Absolute filesystem path to the source FBX file. */
    public function fbxAbsolutePath(): string
    {
        return public_path($this->fbx_path);
    }

    /** Absolute filesystem path to the converted GLB, or null if not yet converted. */
    public function glbAbsolutePath(): ?string
    {
        return $this->glb_path ? public_path($this->glb_path) : null;
    }

    /** True only when the GLB has been successfully generated. */
    public function isConverted(): bool
    {
        return $this->status === 'ready';
    }
}
