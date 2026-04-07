<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Avatar extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'avatar_title',
        'slug',
        'description',
        'portrait_path',
        'subject',
        'voice_provider',
        'voice_id',
        'voice_speed',
        'voice_pitch',
        'voice_settings',
        'is_active',
        'sort_order',
        'portrait_original_path',
        'landmarks_json',
        'sprite_status',
    ];

    protected function casts(): array
    {
        return [
            'voice_settings' => 'array',
            'voice_speed'    => 'float',
            'voice_pitch'    => 'float',
            'is_active'      => 'boolean',
            'sort_order'     => 'integer',
            'landmarks_json' => 'array',
            'sprite_status'  => \App\Enums\SpriteStatus::class,
        ];
    }

    public function spritesReady(): bool
    {
        return $this->sprite_status === \App\Enums\SpriteStatus::Ready;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public function voiceSamples(): HasMany
    {
        return $this->hasMany(AvatarVoiceSample::class)->latest();
    }

    public function teacherFeedback(): HasMany
    {
        return $this->hasMany(AvatarTeacherFeedback::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeForSubject($query, string $subject)
    {
        return $query->where(fn ($q) =>
            $q->where('subject', $subject)->orWhere('subject', 'all')
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * URL for the portrait image (supports both public assets and storage paths).
     */
    public function portraitUrl(): ?string
    {
        if (! $this->portrait_path) {
            return null;
        }

        // Public asset (e.g. assets/professor.webp)
        if (str_starts_with($this->portrait_path, 'assets/')) {
            return asset($this->portrait_path);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->portrait_path);
    }

    // ── Greeting ──────────────────────────────────────────────────────────────

    /**
     * Build the personalised greeting text for a teacher.
     *
     * "Hi there Sarah. I am The Professor, a History Professor
     *  here at The History Portal. Do you like my voice?"
     */
    public function greetingText(string $teacherFirstName): string
    {
        $avatarName  = $this->short_name  ?? $this->name;
        $avatarTitle = $this->avatar_title ?? 'Professor';

        return "Hi there {$teacherFirstName}. "
             . "I am {$avatarName}, a {$avatarTitle} here at The History Portal. "
             . "Do you like my voice?";
    }

    // ── Voice config ──────────────────────────────────────────────────────────

    /**
     * Voice config array passed to TtsService.
     */
    public function voiceConfig(): array
    {
        return [
            'provider' => $this->voice_provider,
            'voice_id' => $this->voice_id,
            'speed'    => $this->voice_speed,
            'pitch'    => $this->voice_pitch,
            'settings' => $this->voice_settings ?? [],
        ];
    }

    /**
     * edge-tts voices (Microsoft neural TTS — FREE, no API key).
     * Install: pip install edge-tts
     * Key voice for The Professor: es-ES-AlvaroNeural (Spanish accent in English).
     */
    public static function edgeTtsVoices(): array
    {
        return [
            // ★ Spanish-accented English — perfect for The Professor
            'es-ES-AlvaroNeural'   => '★ Spanish Male — Alvaro (Spanish accent, speaks English)',
            'es-MX-JorgeNeural'    => 'Mexican Spanish Male — Jorge',

            // Distinguished British male
            'en-GB-RyanNeural'     => 'British Male — Ryan (professional)',
            'en-GB-ThomasNeural'   => 'British Male — Thomas (calm)',

            // American male
            'en-US-GuyNeural'      => 'American Male — Guy (neutral)',
            'en-US-DavisNeural'    => 'American Male — Davis (deep)',
            'en-US-TonyNeural'     => 'American Male — Tony (natural)',

            // Female options
            'en-GB-SoniaNeural'    => 'British Female — Sonia (clear)',
            'en-US-JennyNeural'    => 'American Female — Jenny (friendly)',
            'en-US-AriaNeural'     => 'American Female — Aria (expressive)',
        ];
    }

    /**
     * All Kokoro speaker IDs we support in the studio.
     */
    public static function kokoroVoices(): array
    {
        return [
            // British male — best for a distinguished professor character
            'bm_george' => 'British Male — George (distinguished)',
            'bm_lewis'  => 'British Male — Lewis (authoritative)',
            'bm_daniel' => 'British Male — Daniel (calm)',
            'bm_fable'  => 'British Male — Fable (storytelling)',

            // American male
            'am_adam'    => 'American Male — Adam (neutral)',
            'am_michael' => 'American Male — Michael (natural)',
            'am_fenrir'  => 'American Male — Fenrir (deep)',
            'am_echo'    => 'American Male — Echo',

            // British female
            'bf_emma'     => 'British Female — Emma (polished)',
            'bf_isabella' => 'British Female — Isabella (refined)',

            // American female
            'af_heart'   => 'American Female — Heart (warm)',
            'af_bella'   => 'American Female — Bella (expressive)',
            'af_nicole'  => 'American Female — Nicole (professional)',
            'af_sarah'   => 'American Female — Sarah (clear)',
            'af_sky'     => 'American Female — Sky (bright)',
        ];
    }

    /**
     * The 5 standard sample phrases used in the Voice Studio.
     */
    public static function samplePhrases(): array
    {
        return [
            'Welcome, students. Today we embark on a remarkable journey through history.',
            'This moment changed everything. Let me tell you exactly why it mattered.',
            'Fascinating! And what does this tell us about the nature of power and courage?',
            'The year was 1789. The world would never be the same again.',
            'So, my dear students — what have we learned today, and why should we care?',
        ];
    }
}
