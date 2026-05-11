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
        'presentation_mode',
        'age',
        'gender',
        'emotion_style',
        'expressiveness',
        'speaking_speed',
        'frame_background',
        'skin_tone',
        'body_type',
        'source_id',
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
            'age'            => 'integer',
            'expressiveness' => 'float',
            'speaking_speed' => 'float',
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

        // Public asset paths (avatars/* or assets/*) — served directly from public/
        if (str_starts_with($this->portrait_path, 'avatars/') || str_starts_with($this->portrait_path, 'assets/')) {
            return asset($this->portrait_path);
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($this->portrait_path);
    }

    public function thumbnailUrl(): ?string
    {
        $path = public_path("avatars/{$this->id}/thumbnail.webp");

        return file_exists($path) ? asset("avatars/{$this->id}/thumbnail.webp") : null;
    }

    public function glbUrl(): string
    {
        return asset("avatars/{$this->id}/character.glb");
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
            // ── British Male ──────────────────────────────────────────────────
            'en-GB-RyanNeural'          => '🇬🇧 British Male — Ryan (professional)',
            'en-GB-ThomasNeural'        => '🇬🇧 British Male — Thomas (calm)',
            'en-GB-AlfieNeural'         => '🇬🇧 British Male — Alfie (friendly)',
            'en-GB-ElliotNeural'        => '🇬🇧 British Male — Elliot (natural)',
            'en-GB-EthanNeural'         => '🇬🇧 British Male — Ethan (clear)',
            'en-GB-NoahNeural'          => '🇬🇧 British Male — Noah (warm)',
            'en-GB-OliverNeural'        => '🇬🇧 British Male — Oliver (articulate)',

            // ── British Female ────────────────────────────────────────────────
            'en-GB-SoniaNeural'         => '🇬🇧 British Female — Sonia (clear)',
            'en-GB-MaisieNeural'        => '🇬🇧 British Female — Maisie (warm)',
            'en-GB-AbbiNeural'          => '🇬🇧 British Female — Abbi (confident)',
            'en-GB-BellaNeural'         => '🇬🇧 British Female — Bella (expressive)',
            'en-GB-HollieNeural'        => '🇬🇧 British Female — Hollie (bright)',
            'en-GB-LibbyNeural'         => '🇬🇧 British Female — Libby (polished)',
            'en-GB-OliviaNeural'        => '🇬🇧 British Female — Olivia (authoritative)',

            // ── American Male ─────────────────────────────────────────────────
            'en-US-GuyNeural'           => '🇺🇸 American Male — Guy (neutral)',
            'en-US-DavisNeural'         => '🇺🇸 American Male — Davis (deep)',
            'en-US-TonyNeural'          => '🇺🇸 American Male — Tony (natural)',
            'en-US-JasonNeural'         => '🇺🇸 American Male — Jason (serious)',
            'en-US-AndrewNeural'        => '🇺🇸 American Male — Andrew (warm)',
            'en-US-BrianNeural'         => '🇺🇸 American Male — Brian (casual)',
            'en-US-ChristopherNeural'   => '🇺🇸 American Male — Christopher (authoritative)',
            'en-US-EricNeural'          => '🇺🇸 American Male — Eric (rational)',
            'en-US-RogerNeural'         => '🇺🇸 American Male — Roger (confident)',
            'en-US-SteffanNeural'       => '🇺🇸 American Male — Steffan (clear)',

            // ── American Female ───────────────────────────────────────────────
            'en-US-JennyNeural'         => '🇺🇸 American Female — Jenny (friendly)',
            'en-US-AriaNeural'          => '🇺🇸 American Female — Aria (expressive)',
            'en-US-AnaNeural'           => '🇺🇸 American Female — Ana (cheerful)',
            'en-US-EmmaNeural'          => '🇺🇸 American Female — Emma (bright)',
            'en-US-MichelleNeural'      => '🇺🇸 American Female — Michelle (warm)',
            'en-US-MonicaNeural'        => '🇺🇸 American Female — Monica (calm)',
            'en-US-NancyNeural'         => '🇺🇸 American Female — Nancy (natural)',
            'en-US-SaraNeural'          => '🇺🇸 American Female — Sara (gentle)',

            // ── Irish ─────────────────────────────────────────────────────────
            'en-IE-ConnorNeural'        => '🇮🇪 Irish Male — Connor (storyteller)',
            'en-IE-EmilyNeural'         => '🇮🇪 Irish Female — Emily (warm)',

            // ── Australian ───────────────────────────────────────────────────
            'en-AU-WilliamNeural'       => '🇦🇺 Australian Male — William (authoritative)',
            'en-AU-DarrenNeural'        => '🇦🇺 Australian Male — Darren (casual)',
            'en-AU-DuncanNeural'        => '🇦🇺 Australian Male — Duncan (relaxed)',
            'en-AU-KenNeural'           => '🇦🇺 Australian Male — Ken (deep)',
            'en-AU-NeilNeural'          => '🇦🇺 Australian Male — Neil (calm)',
            'en-AU-TimNeural'           => '🇦🇺 Australian Male — Tim (natural)',
            'en-AU-NatashaNeural'       => '🇦🇺 Australian Female — Natasha (clear)',
            'en-AU-AnnetteNeural'       => '🇦🇺 Australian Female — Annette (warm)',
            'en-AU-CarlyNeural'         => '🇦🇺 Australian Female — Carly (bright)',
            'en-AU-ElsieNeural'         => '🇦🇺 Australian Female — Elsie (gentle)',
            'en-AU-FreyaNeural'         => '🇦🇺 Australian Female — Freya (expressive)',
            'en-AU-JoanneNeural'        => '🇦🇺 Australian Female — Joanne (professional)',

            // ── Canadian ─────────────────────────────────────────────────────
            'en-CA-LiamNeural'          => '🇨🇦 Canadian Male — Liam (natural)',
            'en-CA-ClaraNeural'         => '🇨🇦 Canadian Female — Clara (clear)',

            // ── Indian English ────────────────────────────────────────────────
            'en-IN-PrabhatNeural'       => '🇮🇳 Indian Male — Prabhat (authoritative)',
            'en-IN-AaravNeural'         => '🇮🇳 Indian Male — Aarav (warm)',
            'en-IN-AnanyaNeural'        => '🇮🇳 Indian Female — Ananya (bright)',
            'en-IN-NeerjaNeural'        => '🇮🇳 Indian Female — Neerja (professional)',

            // ── South African ────────────────────────────────────────────────
            'en-ZA-LukeNeural'          => '🇿🇦 South African Male — Luke (deep)',
            'en-ZA-LeahNeural'          => '🇿🇦 South African Female — Leah (clear)',

            // ── Nigerian ─────────────────────────────────────────────────────
            'en-NG-AbeoNeural'          => '🇳🇬 Nigerian Male — Abeo (warm)',
            'en-NG-EzinneNeural'        => '🇳🇬 Nigerian Female — Ezinne (expressive)',

            // ── Spanish-accented English (great for historical figures) ───────
            'es-ES-AlvaroNeural'        => '🇪🇸 Spanish Male — Alvaro (accent)',
            'es-MX-JorgeNeural'         => '🇲🇽 Mexican Male — Jorge (warm)',

            // ── French-accented English ───────────────────────────────────────
            'fr-FR-HenriNeural'         => '🇫🇷 French Male — Henri (sophisticated)',
            'fr-FR-DeniseNeural'        => '🇫🇷 French Female — Denise (elegant)',

            // ── Italian-accented English ──────────────────────────────────────
            'it-IT-DiegoNeural'         => '🇮🇹 Italian Male — Diego (expressive)',
            'it-IT-ElsaNeural'          => '🇮🇹 Italian Female — Elsa (warm)',

            // ── German-accented English ───────────────────────────────────────
            'de-DE-ConradNeural'        => '🇩🇪 German Male — Conrad (serious)',
            'de-DE-KatjaNeural'         => '🇩🇪 German Female — Katja (clear)',
        ];
    }

    /**
     * Maps ElevenLabs voice label metadata to gradient class.
     * Mirrors ElevenLabsService::gradientClass() for convenience.
     */
    public static function elevenlabsVoiceGradient(array $labels): string
    {
        $accent = strtolower($labels['accent'] ?? '');
        $gender = strtolower($labels['gender'] ?? '');

        if (str_contains($accent, 'british') && $gender === 'male') {
            return 'vg-navy';
        }

        if (str_contains($accent, 'narration') || str_contains($accent, 'calm')) {
            return 'vg-teal';
        }

        if (str_contains($accent, 'american') || str_contains($accent, 'neutral')) {
            return $gender === 'female' ? 'vg-violet' : 'vg-indigo';
        }

        if ($gender === 'female') {
            return 'vg-violet';
        }

        if ($accent !== '' && ! str_contains($accent, 'american') && ! str_contains($accent, 'british')) {
            return 'vg-amber';
        }

        return 'vg-base';
    }

    /**
     * Edge TTS voices in card shape: [id, label, preview_url, gradient_class].
     */
    public static function edgeTtsVoicesForCards(): array
    {
        $voices = static::edgeTtsVoices();
        $cards  = [];

        foreach ($voices as $id => $label) {
            $cards[] = [
                'id'             => $id,
                'label'          => $label,
                'preview_url'    => '',
                'gradient_class' => static::edgeTtsGradientClass($id),
            ];
        }

        return $cards;
    }

    /**
     * Determine gradient class for an Edge TTS voice by ID.
     */
    private static function edgeTtsGradientClass(string $voiceId): string
    {
        if (str_starts_with($voiceId, 'en-GB-')) {
            return 'vg-navy';
        }

        if (str_starts_with($voiceId, 'en-US-') || str_starts_with($voiceId, 'en-CA-')) {
            return 'vg-indigo';
        }

        if (str_starts_with($voiceId, 'en-AU-') || str_starts_with($voiceId, 'en-NZ-')) {
            return 'vg-teal';
        }

        // Non-English locale → amber
        if (! str_starts_with($voiceId, 'en-')) {
            return 'vg-amber';
        }

        return 'vg-base';
    }

    /**
     * Pocket TTS preset voices in card shape.
     */
    public static function pocketTtsVoices(): array
    {
        return [
            ['id' => 'default',  'label' => 'Default Voice',  'preview_url' => '', 'gradient_class' => 'vg-teal'],
            ['id' => 'male_1',   'label' => 'Male Voice 1',   'preview_url' => '', 'gradient_class' => 'vg-indigo'],
            ['id' => 'female_1', 'label' => 'Female Voice 1', 'preview_url' => '', 'gradient_class' => 'vg-violet'],
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
