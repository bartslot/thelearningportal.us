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
        'intro_video_path',
        'subject',
        'voice_provider',
        'voice_id',
        'voice_map',
        'voice_speed',
        'voice_pitch',
        'voice_settings',
        'is_active',
        'sort_order',
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
        'morph_status',   // 'pending' | 'processing' | 'ready' | 'failed'
    ];

    protected function casts(): array
    {
        return [
            'voice_map' => 'array',
            'voice_settings' => 'array',
            'voice_speed' => 'float',
            'voice_pitch' => 'float',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'age' => 'integer',
            'expressiveness' => 'float',
            'speaking_speed' => 'float',
        ];
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
        return $query->where(fn ($q) => $q->where('subject', $subject)->orWhere('subject', 'all')
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

    /**
     * URL for the narrator welcome video (talking-avatar intro that plays once,
     * full-screen, before the first lesson block). Null when the avatar has none.
     */
    public function welcomeVideoUrl(): ?string
    {
        if ($this->intro_video_path) {
            if (str_starts_with($this->intro_video_path, 'avatars/') || str_starts_with($this->intro_video_path, 'assets/')) {
                return asset($this->intro_video_path);
            }

            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->intro_video_path);
        }

        // Backwards compatibility for avatars created before intro_video_path existed.
        $path = public_path("avatars/{$this->id}/welcome.mp4");

        return file_exists($path) ? asset("avatars/{$this->id}/welcome.mp4") : null;
    }

    /**
     * Smaller (480px) welcome video for slow / data-saver connections and phones.
     * The player picks this over the full file via the Network Information API.
     */
    public function welcomeVideoLiteUrl(): ?string
    {
        $path = public_path("avatars/{$this->id}/welcome-lite.mp4");

        return file_exists($path) ? asset("avatars/{$this->id}/welcome-lite.mp4") : null;
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
        $avatarName = $this->short_name ?? $this->name;
        $avatarTitle = $this->avatar_title ?? 'Professor';

        return "Hi there {$teacherFirstName}. "
             ."I am {$avatarName}, a {$avatarTitle} here at The History Portal. "
             .'Do you like my voice?';
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
            'speed' => $this->voice_speed,
            'pitch' => $this->voice_pitch,
            'settings' => $this->voice_settings ?? [],
        ];
    }

    /**
     * Structured edge-tts catalog, EUROPEAN-focused (founder direction 2026-07-11).
     * Built from real `edge-tts --list-voices` output — every entry verified
     * synthesizable; voices:samples validates again on regeneration.
     *
     * @return list<array{id: string, name: string, language: string, lang: string, gender: string, flag: string, note: string}>
     */
    public static function edgeTtsCatalog(): array
    {
        $mk = fn (string $id, string $name, string $language, string $lang, string $gender, string $flag, string $note = ''): array => compact('id', 'name', 'language', 'lang', 'gender', 'flag', 'note');

        return [
            // ── Nederlands ──
            $mk('nl-NL-FennaNeural', 'Fenna', 'Nederlands', 'nl', 'V', '🇳🇱', 'warm'),
            $mk('nl-NL-ColetteNeural', 'Colette', 'Nederlands', 'nl', 'V', '🇳🇱', 'helder'),
            $mk('nl-NL-MaartenNeural', 'Maarten', 'Nederlands', 'nl', 'M', '🇳🇱', 'kalm'),
            $mk('nl-BE-DenaNeural', 'Dena', 'Nederlands', 'nl', 'V', '🇧🇪', 'Vlaams'),
            $mk('nl-BE-ArnaudNeural', 'Arnaud', 'Nederlands', 'nl', 'M', '🇧🇪', 'Vlaams'),
            // ── English ──
            $mk('en-GB-RyanNeural', 'Ryan', 'English', 'en', 'M', '🇬🇧', 'professional'),
            $mk('en-GB-ThomasNeural', 'Thomas', 'English', 'en', 'M', '🇬🇧', 'calm'),
            $mk('en-GB-SoniaNeural', 'Sonia', 'English', 'en', 'V', '🇬🇧', 'clear'),
            $mk('en-GB-MaisieNeural', 'Maisie', 'English', 'en', 'V', '🇬🇧', 'warm'),
            $mk('en-GB-LibbyNeural', 'Libby', 'English', 'en', 'V', '🇬🇧', 'polished'),
            $mk('en-IE-ConnorNeural', 'Connor', 'English', 'en', 'M', '🇮🇪', 'storyteller'),
            $mk('en-IE-EmilyNeural', 'Emily', 'English', 'en', 'V', '🇮🇪', 'warm'),
            $mk('en-US-AndrewNeural', 'Andrew', 'English', 'en', 'M', '🇺🇸', 'warm'),
            $mk('en-US-JennyNeural', 'Jenny', 'English', 'en', 'V', '🇺🇸', 'friendly'),
            // ── Deutsch ──
            $mk('de-DE-ConradNeural', 'Conrad', 'Deutsch', 'de', 'M', '🇩🇪', 'ernst'),
            $mk('de-DE-KillianNeural', 'Killian', 'Deutsch', 'de', 'M', '🇩🇪', ''),
            $mk('de-DE-KatjaNeural', 'Katja', 'Deutsch', 'de', 'V', '🇩🇪', 'klar'),
            $mk('de-DE-AmalaNeural', 'Amala', 'Deutsch', 'de', 'V', '🇩🇪', 'weich'),
            $mk('de-AT-JonasNeural', 'Jonas', 'Deutsch', 'de', 'M', '🇦🇹', 'Österreich'),
            $mk('de-AT-IngridNeural', 'Ingrid', 'Deutsch', 'de', 'V', '🇦🇹', 'Österreich'),
            $mk('de-CH-JanNeural', 'Jan', 'Deutsch', 'de', 'M', '🇨🇭', 'Schweiz'),
            $mk('de-CH-LeniNeural', 'Leni', 'Deutsch', 'de', 'V', '🇨🇭', 'Schweiz'),
            // ── Français ──
            $mk('fr-FR-HenriNeural', 'Henri', 'Français', 'fr', 'M', '🇫🇷', 'sophistiqué'),
            $mk('fr-FR-RemyMultilingualNeural', 'Rémy', 'Français', 'fr', 'M', '🇫🇷', 'multilingual'),
            $mk('fr-FR-DeniseNeural', 'Denise', 'Français', 'fr', 'V', '🇫🇷', 'élégante'),
            $mk('fr-FR-EloiseNeural', 'Eloise', 'Français', 'fr', 'V', '🇫🇷', ''),
            $mk('fr-BE-GerardNeural', 'Gérard', 'Français', 'fr', 'M', '🇧🇪', 'Belgique'),
            $mk('fr-BE-CharlineNeural', 'Charline', 'Français', 'fr', 'V', '🇧🇪', 'Belgique'),
            $mk('fr-CH-FabriceNeural', 'Fabrice', 'Français', 'fr', 'M', '🇨🇭', 'Suisse'),
            $mk('fr-CH-ArianeNeural', 'Ariane', 'Français', 'fr', 'V', '🇨🇭', 'Suisse'),
            // ── Italiano ──
            $mk('it-IT-DiegoNeural', 'Diego', 'Italiano', 'it', 'M', '🇮🇹', 'espressivo'),
            $mk('it-IT-GiuseppeMultilingualNeural', 'Giuseppe', 'Italiano', 'it', 'M', '🇮🇹', 'multilingual'),
            $mk('it-IT-ElsaNeural', 'Elsa', 'Italiano', 'it', 'V', '🇮🇹', 'calda'),
            $mk('it-IT-IsabellaNeural', 'Isabella', 'Italiano', 'it', 'V', '🇮🇹', ''),
            // ── Español ──
            $mk('es-ES-AlvaroNeural', 'Álvaro', 'Español', 'es', 'M', '🇪🇸', ''),
            $mk('es-ES-ElviraNeural', 'Elvira', 'Español', 'es', 'V', '🇪🇸', ''),
            $mk('es-ES-XimenaNeural', 'Ximena', 'Español', 'es', 'V', '🇪🇸', ''),
            // ── Português ──
            $mk('pt-PT-DuarteNeural', 'Duarte', 'Português', 'pt', 'M', '🇵🇹', ''),
            $mk('pt-PT-RaquelNeural', 'Raquel', 'Português', 'pt', 'V', '🇵🇹', ''),
            // ── Polski ──
            $mk('pl-PL-MarekNeural', 'Marek', 'Polski', 'pl', 'M', '🇵🇱', ''),
            $mk('pl-PL-ZofiaNeural', 'Zofia', 'Polski', 'pl', 'V', '🇵🇱', ''),
            // ── Svenska ──
            $mk('sv-SE-MattiasNeural', 'Mattias', 'Svenska', 'sv', 'M', '🇸🇪', ''),
            $mk('sv-SE-SofieNeural', 'Sofie', 'Svenska', 'sv', 'V', '🇸🇪', ''),
            // ── Dansk ──
            $mk('da-DK-JeppeNeural', 'Jeppe', 'Dansk', 'da', 'M', '🇩🇰', ''),
            $mk('da-DK-ChristelNeural', 'Christel', 'Dansk', 'da', 'V', '🇩🇰', ''),
            // ── Norsk ──
            $mk('nb-NO-FinnNeural', 'Finn', 'Norsk', 'nb', 'M', '🇳🇴', ''),
            $mk('nb-NO-PernilleNeural', 'Pernille', 'Norsk', 'nb', 'V', '🇳🇴', ''),
            // ── Suomi ──
            $mk('fi-FI-HarriNeural', 'Harri', 'Suomi', 'fi', 'M', '🇫🇮', ''),
            $mk('fi-FI-NooraNeural', 'Noora', 'Suomi', 'fi', 'V', '🇫🇮', ''),
            // ── Ελληνικά ──
            $mk('el-GR-NestorasNeural', 'Nestoras', 'Ελληνικά', 'el', 'M', '🇬🇷', ''),
            $mk('el-GR-AthinaNeural', 'Athina', 'Ελληνικά', 'el', 'V', '🇬🇷', ''),
            // ── Čeština ──
            $mk('cs-CZ-AntoninNeural', 'Antonín', 'Čeština', 'cs', 'M', '🇨🇿', ''),
            $mk('cs-CZ-VlastaNeural', 'Vlasta', 'Čeština', 'cs', 'V', '🇨🇿', ''),
            // ── Magyar ──
            $mk('hu-HU-TamasNeural', 'Tamás', 'Magyar', 'hu', 'M', '🇭🇺', ''),
            $mk('hu-HU-NoemiNeural', 'Noémi', 'Magyar', 'hu', 'V', '🇭🇺', ''),
            // ── Română ──
            $mk('ro-RO-EmilNeural', 'Emil', 'Română', 'ro', 'M', '🇷🇴', ''),
            $mk('ro-RO-AlinaNeural', 'Alina', 'Română', 'ro', 'V', '🇷🇴', ''),
            // ── Українська ──
            $mk('uk-UA-OstapNeural', 'Ostap', 'Українська', 'uk', 'M', '🇺🇦', ''),
            $mk('uk-UA-PolinaNeural', 'Polina', 'Українська', 'uk', 'V', '🇺🇦', ''),
            // ── Gaeilge ──
            $mk('ga-IE-ColmNeural', 'Colm', 'Gaeilge', 'ga', 'M', '🇮🇪', ''),
            $mk('ga-IE-OrlaNeural', 'Orla', 'Gaeilge', 'ga', 'V', '🇮🇪', ''),
        ];
    }

    /**
     * Back-compat flat map derived from the structured catalog.
     *
     * @return array<string, string> id => display label
     */
    public static function edgeTtsVoices(): array
    {
        $out = [];
        foreach (static::edgeTtsCatalog() as $v) {
            $note = $v['note'] !== '' ? " ({$v['note']})" : '';
            $gender = $v['gender'] === 'M' ? 'Man' : 'Vrouw';
            $out[$v['id']] = "{$v['flag']} {$v['language']} {$gender} — {$v['name']}{$note}";
        }

        return $out;
    }

    /**
     * Preferred narration voice for a lesson language: the ticked per-language
     * voice when it matches the avatar's provider, else the avatar's base voice.
     */
    public function voiceFor(?string $locale): string
    {
        $mapped = (string) (($this->voice_map ?? [])[$locale ?? ''] ?? '');
        if ($mapped === '') {
            return (string) $this->voice_id;
        }

        // An edge/azure voice id looks like xx-XX-NameNeural; ElevenLabs ids don't.
        $looksNeural = (bool) preg_match('/^[a-z]{2}-[A-Z]{2}-.+Neural$/', $mapped);
        $neuralProvider = in_array($this->voice_provider, ['edge_tts', 'azure'], true);

        return $looksNeural === $neuralProvider ? $mapped : (string) $this->voice_id;
    }

    /**
     * Edge TTS voices in card shape: [id, label, preview_url, gradient_class].
     */
    /**
     * The audition shortlist: the 2 best male + 2 best female voices per language.
     * Everything else stays selectable behind the "show all" toggle in the studio.
     */
    public static function edgeTtsFeatured(): array
    {
        return [
            // Nederlands (incl. Vlaams): 2 vrouwen + 2 mannen
            'nl-NL-FennaNeural', 'nl-NL-ColetteNeural', 'nl-NL-MaartenNeural', 'nl-BE-ArnaudNeural',
            // English: 2 male + 2 female
            'en-GB-RyanNeural', 'en-US-AndrewNeural', 'en-GB-SoniaNeural', 'en-US-JennyNeural',
        ];
    }

    public static function edgeTtsVoicesForCards(): array
    {
        $voices = static::edgeTtsVoices();
        $featured = static::edgeTtsFeatured();
        $cards = [];

        foreach ($voices as $id => $label) {
            // Pre-generated audition sample (voices:samples command) — played on click,
            // served as a static asset so the host CDN caches it. No runtime TTS.
            $samplePath = "voices/edge/{$id}.mp3";

            $cards[] = [
                'id' => $id,
                'label' => $label,
                'preview_url' => is_file(public_path($samplePath)) ? asset($samplePath) : '',
                'gradient_class' => static::edgeTtsGradientClass($id),
                'featured' => in_array($id, $featured, true),
            ];
        }

        return $cards;
    }

    /**
     * Determine gradient class for an Edge TTS voice by ID.
     */
    private static function edgeTtsGradientClass(string $voiceId): string
    {
        if (str_starts_with($voiceId, 'nl-')) {
            return 'vg-amber';
        }

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
