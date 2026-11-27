<?php

namespace App\Covers;

use App\Novel;
use App\NovelChapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The visual brief an AI cover is painted from: a local LLM (Ollama,
 * novarr.covers.ai.ollama_url / brief_model) reads the series' title,
 * author, genres, medium, origin, description and the first
 * novarr.covers.ai.brief_words words of its opening chapters, and answers
 * one JSON object:
 *
 *   genre      "xianxia", "space opera", …
 *   setting    where it happens ("a misty mountain sect")
 *   mood       "ominous", "hopeful", …
 *   era        "ancient", "near future", …
 *   palette    2–4 colour words
 *   key_scene  ONE vivid visual scene: no names, no text
 *   subject    the main character's look, when the text makes it clear
 *   style      "painterly digital art", "manhwa-style illustration", …
 *
 * parse() cleans the answer (a qwen3 <think>…</think> block and code fences
 * stripped, the outermost {…} taken, every field a short single line, names
 * and quoted words left to the prompt's "no text"); an answer without a
 * key_scene is no answer. When the model is not set up, unreachable or
 * talks nonsense, fallback() builds a brief from the genres and the origin
 * alone, so a cover can always be painted.
 */
class CoverBrief
{
    public const FIELDS = ['genre', 'setting', 'mood', 'era', 'palette', 'key_scene', 'subject', 'style'];

    /** Longest value kept per field (characters). */
    public const MAX_LENGTHS = ['key_scene' => 260, 'subject' => 160, 'setting' => 120, 'style' => 80];
    public const MAX_LENGTH = 60;

    /** Description characters sent (the chapters bring the rest). */
    public const MAX_DESCRIPTION = 2000;

    public const SOURCE_LLM = 'llm';
    public const SOURCE_FALLBACK = 'fallback';

    /**
     * Genre words (lower case, matched in tags and the description) => a
     * fallback brief. First match wins; the last one is the default.
     */
    public const GENRE_BRIEFS = [
        ['match' => ['xianxia', 'xuanhuan', 'wuxia', 'cultivation', 'martial arts', 'murim'], 'genre' => 'cultivation fantasy', 'setting' => 'misty mountain peaks with floating temples', 'mood' => 'majestic', 'era' => 'ancient', 'palette' => ['jade green', 'gold', 'mist white'], 'key_scene' => 'a lone robed cultivator standing on a cliff edge above a sea of clouds, qi swirling around them like wind', 'style' => 'chinese ink and digital painting'],
        ['match' => ['litrpg', 'gamelit', 'system', 'dungeon', 'game', 'progression'], 'genre' => 'litrpg fantasy', 'setting' => 'the mouth of a vast glowing dungeon', 'mood' => 'adventurous', 'era' => 'timeless', 'palette' => ['electric blue', 'amber', 'deep purple'], 'key_scene' => 'an adventurer facing the glowing entrance of an enormous dungeon, faint holographic runes floating in the air', 'style' => 'vibrant fantasy concept art'],
        ['match' => ['sci-fi', 'science fiction', 'space', 'mecha', 'cyberpunk'], 'genre' => 'science fiction', 'setting' => 'a vast starship above an alien world', 'mood' => 'awe-struck', 'era' => 'far future', 'palette' => ['deep blue', 'cyan', 'silver'], 'key_scene' => 'a lone figure at a huge observation window watching a fleet of starships drift over a ringed planet', 'style' => 'sci-fi concept art'],
        ['match' => ['apocalypse', 'post-apocalyptic', 'zombie', 'survival'], 'genre' => 'post-apocalyptic', 'setting' => 'a ruined overgrown city', 'mood' => 'desolate', 'era' => 'near future', 'palette' => ['rust orange', 'ash grey', 'sickly green'], 'key_scene' => 'a survivor walking down a cracked highway through a ruined city swallowed by vines under a burning sky', 'style' => 'cinematic digital painting'],
        ['match' => ['horror', 'thriller', 'supernatural', 'psychological'], 'genre' => 'horror', 'setting' => 'a fog-bound old house at night', 'mood' => 'ominous', 'era' => 'timeless', 'palette' => ['black', 'blood red', 'cold grey'], 'key_scene' => 'a dim lantern glowing in the window of a crooked house half-hidden in fog, long shadows reaching across the lawn', 'style' => 'dark atmospheric oil painting'],
        ['match' => ['mystery', 'detective', 'crime'], 'genre' => 'mystery', 'setting' => 'rain-soaked city streets at night', 'mood' => 'tense', 'era' => 'modern', 'palette' => ['midnight blue', 'neon amber', 'black'], 'key_scene' => 'a silhouetted figure under a single streetlamp on a rain-slicked street, reflections shimmering in puddles', 'style' => 'noir digital painting'],
        ['match' => ['romance', 'josei', 'shoujo', 'love'], 'genre' => 'romance', 'setting' => 'a blossoming garden at sunset', 'mood' => 'tender', 'era' => 'timeless', 'palette' => ['rose pink', 'warm gold', 'lavender'], 'key_scene' => 'two figures seen from behind beneath a tree of falling blossoms, the sky glowing at sunset', 'style' => 'soft painterly illustration'],
        ['match' => ['historical', 'history', 'war', 'military'], 'genre' => 'historical', 'setting' => 'a sweeping battlefield at dawn', 'mood' => 'epic', 'era' => 'historical', 'palette' => ['bronze', 'smoke grey', 'crimson'], 'key_scene' => 'banners rising through smoke over a sweeping battlefield at dawn, a lone commander on horseback on a ridge', 'style' => 'epic historical oil painting'],
        ['match' => ['school', 'slice of life', 'comedy', 'drama'], 'genre' => 'slice of life', 'setting' => 'a quiet town street in late afternoon', 'mood' => 'warm', 'era' => 'modern', 'palette' => ['peach', 'sky blue', 'cream'], 'key_scene' => 'a quiet tree-lined street in golden afternoon light, cherry petals drifting past a school gate', 'style' => 'soft painterly illustration'],
        ['match' => ['fantasy', 'magic', 'adventure', 'isekai', 'reincarnation', 'action'], 'genre' => 'fantasy', 'setting' => 'a sprawling fantasy kingdom', 'mood' => 'adventurous', 'era' => 'medieval', 'palette' => ['royal blue', 'gold', 'emerald'], 'key_scene' => 'a cloaked traveller on a hilltop gazing at a towering castle city under a sky of twin moons', 'style' => 'epic fantasy oil painting'],
        ['match' => [], 'genre' => 'fiction', 'setting' => 'an evocative landscape', 'mood' => 'mysterious', 'era' => 'timeless', 'palette' => ['deep teal', 'amber', 'charcoal'], 'key_scene' => 'a lone figure on a winding path toward a distant glowing horizon under a dramatic sky', 'style' => 'painterly digital art'],
    ];

    /**
     * The brief for a series: the LLM's when it answers sensibly, else the
     * genre fallback.
     *
     * @return array{brief: array<string, mixed>, source: string, model: ?string, error: ?string}
     */
    public function build(Novel $novel): array
    {
        $error = null;
        if (static::enabled()) {
            try {
                $raw = $this->ask(static::prompt(static::context($novel)));
                $brief = static::parse($raw);
                if ($brief !== null) {
                    $brief['style'] = $brief['style'] !== '' ? $brief['style'] : static::defaultStyle($novel);

                    return ['brief' => $brief, 'source' => self::SOURCE_LLM, 'model' => static::model(), 'error' => null];
                }
                $error = 'the model gave no usable brief';
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            Log::info("AI cover #{$novel->id}: brief from the genres ({$error})");
        } else {
            $error = 'no LLM set up';
        }

        return ['brief' => static::fallback($novel), 'source' => self::SOURCE_FALLBACK, 'model' => null, 'error' => $error];
    }

    public static function enabled(): bool
    {
        return trim((string) config('novarr.covers.ai.ollama_url')) !== '' && static::model() !== '';
    }

    public static function model(): string
    {
        return trim((string) config('novarr.covers.ai.brief_model', 'qwen3:8b'));
    }

    /**
     * What the model reads about the series.
     *
     * @return array{title: string, author: string, genres: list<string>, medium: string, origin: string, description: string, opening: string}
     */
    public static function context(Novel $novel): array
    {
        $genres = $novel->relationLoaded('tags') ? $novel->tags : $novel->tags()->get();

        return [
            'title' => trim((string) $novel->name),
            'author' => trim((string) $novel->author),
            'genres' => $genres->pluck('name')->map(fn($g) => trim((string) $g))->filter()->values()->all(),
            'medium' => static::mediumLabel($novel),
            'origin' => static::originLabel($novel),
            'description' => mb_substr(static::plain((string) $novel->description), 0, self::MAX_DESCRIPTION),
            'opening' => static::opening($novel, max(200, (int) config('novarr.covers.ai.brief_words', 3000))),
        ];
    }

    /** "Web Novel", "Manga" or "Book". */
    public static function mediumLabel(Novel $novel): string
    {
        return match ($novel->medium) {
            Novel::MEDIUM_MANGA => 'Manga',
            Novel::MEDIUM_BOOK => 'Book',
            Novel::MEDIUM_COMIC => 'Comic',
            default => 'Web Novel',
        };
    }

    /** "translated from Korean", "original English web fiction", or "unknown". */
    public static function originLabel(Novel $novel): string
    {
        $country = $novel->originCountry();
        $language = ['JP' => 'Japanese', 'KR' => 'Korean', 'CN' => 'Chinese'][$country] ?? null;
        if ($language !== null) {
            return "translated from {$language}";
        }
        if ($country === 'EN' || $novel->isTranslated() === false) {
            return $novel->isBook() ? 'English-language book' : 'original English web fiction';
        }

        return $novel->isTranslated() === true ? 'translated (East Asian)' : 'unknown';
    }

    /** The style when the model names none: manhwa / anime-like for CN, KR, JP; painterly otherwise. */
    public static function defaultStyle(Novel $novel): string
    {
        return match ($novel->originCountry()) {
            'KR' => 'manhwa-style illustration',
            'JP' => 'anime light novel illustration',
            'CN' => 'donghua-style digital painting',
            default => 'painterly digital art',
        };
    }

    /** The first $words words of the opening chapters (author's notes left out). */
    public static function opening(Novel $novel, int $words): string
    {
        $text = '';
        $chapters = NovelChapter::where('novel_id', $novel->id)
            ->where('blacklist', 0)
            ->where(fn($q) => $q->whereNull('kind')->orWhere('kind', '!=', NovelChapter::KIND_NOTE))
            ->ordered()
            ->with('text')
            ->limit(3)
            ->get();
        foreach ($chapters as $chapter) {
            $body = static::plain((string) $chapter->rawText());
            if ($body === '') {
                continue;
            }
            $text .= ($text === '' ? '' : "\n\n") . $body;
            if (str_word_count($text) >= $words) {
                break;
            }
        }
        $parts = preg_split('/(\s+)/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($parts, 0, $words));
    }

    /** HTML to plain text on one paragraph per line. */
    public static function plain(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/div|\/h\d)\b[^>]*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\n\s*\n+/', "\n", $text) ?? $text);
    }

    /** The prompt sent to the model. */
    public static function prompt(array $context): string
    {
        $genres = $context['genres'] !== [] ? implode(', ', $context['genres']) : 'unknown';
        $styleHint = str_starts_with($context['origin'], 'translated')
            ? 'This story is translated from an East Asian language: prefer a matching illustration style (manhwa, anime light novel, donghua, Chinese ink and digital painting) unless the story itself suggests otherwise.'
            : 'This story is not an East Asian translation: prefer a Western book-cover style (painterly digital art, oil painting, concept art) that suits its genre.';

        return <<<PROMPT
You are an art director briefing an illustrator who will paint the cover of a story. Read the details and the opening, then describe ONE striking cover image that fits this story.

Answer with a single JSON object and nothing else, with these keys:
- "genre": the story's genre in 1-3 words
- "setting": where the cover scene takes place, a short phrase
- "mood": 1-2 words
- "era": e.g. "ancient", "medieval", "modern", "near future", "far future"
- "palette": an array of 2 to 4 colour words
- "key_scene": one vivid visual scene for the cover, 12-30 words, describing only what can be seen. No character names, no place names, no titles, no words or lettering.
- "subject": what the main character looks like, if the text makes it clear (age, hair, clothes), else ""
- "style": the art style, e.g. "painterly digital art", "manhwa-style illustration", "anime light novel illustration", "epic fantasy oil painting", "sci-fi concept art", "dark atmospheric oil painting"

{$styleHint}

Title: {$context['title']}
Author: {$context['author']}
Medium: {$context['medium']}
Origin: {$context['origin']}
Genres: {$genres}

Description:
{$context['description']}

Opening chapters:
{$context['opening']}
PROMPT;
    }

    /**
     * Ask Ollama (/api/generate, JSON mode, not streamed, no thinking).
     * A server too old for "think" (400 naming it) is asked again without.
     *
     * @throws \RuntimeException  no answer
     */
    public function ask(string $prompt): string
    {
        $url = rtrim((string) config('novarr.covers.ai.ollama_url'), '/') . '/api/generate';
        $payload = [
            'model' => static::model(),
            'prompt' => $prompt,
            'format' => 'json',
            'stream' => false,
            'think' => false,
            'options' => ['temperature' => 0.7, 'num_ctx' => 8192],
        ];
        $timeout = max(10, (int) config('novarr.covers.ai.brief_timeout', 120));
        $response = Http::timeout($timeout)->connectTimeout(10)->acceptJson()->post($url, $payload);
        if ($response->status() === 400 && str_contains(strtolower($response->body()), 'think')) {
            unset($payload['think']);
            $response = Http::timeout($timeout)->connectTimeout(10)->acceptJson()->post($url, $payload);
        }
        if (!$response->successful()) {
            throw new \RuntimeException('the LLM answered HTTP ' . $response->status());
        }
        $answer = $response->json('response');
        if (!is_string($answer) || trim($answer) === '') {
            // /api/chat shape, for a proxy that answers like it.
            $answer = $response->json('message.content');
        }
        if (!is_string($answer) || trim($answer) === '') {
            throw new \RuntimeException('the LLM gave an empty answer');
        }

        return $answer;
    }

    /**
     * The model's answer as a clean brief, or null when it is not one.
     *
     * @return ?array<string, mixed>
     */
    public static function parse(string $raw): ?array
    {
        // qwen3 (and other reasoning models) think aloud first.
        $text = preg_replace('/<think>.*?<\/think>/is', '', $raw) ?? $raw;
        $text = preg_replace('/^.*<\/think>/is', '', $text) ?? $text; // an unopened block
        $text = preg_replace('/```(?:json)?/i', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            return null;
        }
        // "key_scene", "Key Scene", "keyScene" alike.
        $keyed = [];
        foreach ($data as $key => $value) {
            $normal = preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', (string) $key) ?? (string) $key;
            $normal = trim(strtolower(preg_replace('/[^a-z]+/i', '_', $normal) ?? ''), '_');
            $keyed[$normal] ??= $value;
        }
        $data = $keyed;

        $brief = [];
        foreach (self::FIELDS as $field) {
            $brief[$field] = $field === 'palette' ? static::cleanPalette($data[$field] ?? []) : static::cleanText($data[$field] ?? '', self::MAX_LENGTHS[$field] ?? self::MAX_LENGTH);
        }
        if (mb_strlen($brief['key_scene']) < 10) {
            return null;
        }

        return $brief;
    }

    /** One short line: no quotes (quoted words get painted as lettering), no markup. */
    public static function cleanText(mixed $value, int $max): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(fn($v) => is_scalar($v) ? (string) $v : '', $value)));
        }
        if (!is_scalar($value)) {
            return '';
        }
        $text = strip_tags((string) $value);
        $text = preg_replace('/["“”«»「」『』]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B,;.");
        if (in_array(strtolower($text), ['none', 'n/a', 'unknown', 'null', 'unclear'], true)) {
            return '';
        }
        if (mb_strlen($text) > $max) {
            $cut = mb_substr($text, 0, $max);
            $space = mb_strrpos($cut, ' ');
            $text = rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,;');
        }

        return $text;
    }

    /** @return list<string>  2–4 colour words (fewer when the model gave fewer) */
    public static function cleanPalette(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/\s*(?:,|;|\/|\band\b)\s*/i', is_scalar($value) ? (string) $value : '');
        $colours = [];
        foreach ((array) $items as $item) {
            $colour = strtolower(static::cleanText($item, 24));
            if ($colour !== '' && !in_array($colour, $colours, true)) {
                $colours[] = $colour;
            }
        }

        return array_slice($colours, 0, 4);
    }

    /**
     * A brief from the genres (tags, then the description's words) and the
     * origin: when the model cannot help.
     *
     * @return array<string, mixed>
     */
    public static function fallback(Novel $novel): array
    {
        $genres = $novel->relationLoaded('tags') ? $novel->tags : $novel->tags()->get();
        $haystacks = [
            ' ' . strtolower($genres->pluck('name')->implode(' | ')) . ' ',
            ' ' . strtolower(mb_substr(static::plain((string) $novel->description), 0, self::MAX_DESCRIPTION)) . ' ',
        ];
        $chosen = self::GENRE_BRIEFS[count(self::GENRE_BRIEFS) - 1];
        foreach ($haystacks as $haystack) {
            foreach (self::GENRE_BRIEFS as $candidate) {
                foreach ($candidate['match'] as $word) {
                    if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $haystack)) {
                        $chosen = $candidate;
                        break 3;
                    }
                }
            }
        }
        $style = $chosen['style'];
        if (in_array($novel->originCountry(), ['KR', 'JP', 'CN'], true) && $chosen['genre'] !== 'cultivation fantasy') {
            $style = static::defaultStyle($novel);
        }

        return [
            'genre' => $chosen['genre'],
            'setting' => $chosen['setting'],
            'mood' => $chosen['mood'],
            'era' => $chosen['era'],
            'palette' => $chosen['palette'],
            'key_scene' => $chosen['key_scene'],
            'subject' => '',
            'style' => $style,
        ];
    }
}
