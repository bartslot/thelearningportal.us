<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CloudinaryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The uploader bakes a delivery transformation (f_auto,q_auto,c_limit,w_2400) into every URL so
 * Cloudinary serves AVIF/WebP at auto-quality — verified here at the URL level (the live upload +
 * format negotiation is exercised manually against the real API).
 */
class CloudinaryServiceTest extends TestCase
{
    private function service(): CloudinaryService
    {
        config(['services.cloudinary.url' => 'cloudinary://key:secret@democloud']);

        return new CloudinaryService;
    }

    public function test_optimize_inserts_the_delivery_transform_into_a_plain_cloudinary_url(): void
    {
        $in = 'https://res.cloudinary.com/democloud/image/upload/v1699999999/lessons/1/abc.jpg';
        $out = $this->service()->optimize($in);

        $this->assertSame(
            'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_limit,w_2400/v1699999999/lessons/1/abc.jpg',
            $out,
        );
    }

    public function test_optimize_is_idempotent_and_leaves_already_transformed_urls_alone(): void
    {
        $already = 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_limit,w_2400/v1/lessons/1/abc.jpg';
        $this->assertSame($already, $this->service()->optimize($already));
    }

    public function test_optimize_leaves_non_cloudinary_urls_untouched(): void
    {
        $foreign = 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Tasman.jpg';
        $this->assertSame($foreign, $this->service()->optimize($foreign));
    }

    public function test_not_configured_when_url_is_empty(): void
    {
        config(['services.cloudinary.url' => null]);
        $this->assertFalse((new CloudinaryService)->configured());
    }

    public function test_thumb_replaces_an_existing_transform_instead_of_stacking_one(): void
    {
        $stored = 'https://res.cloudinary.com/democloud/image/upload/f_auto,q_auto,c_limit,w_2400/v1/lessons/1/abc.jpg';

        $this->assertSame(
            'https://res.cloudinary.com/democloud/image/upload/c_fill,w_400,h_250,f_auto,q_auto/v1/lessons/1/abc.jpg',
            $this->service()->thumb($stored),
        );
    }

    public function test_thumb_adds_a_transform_to_a_plain_url_and_leaves_foreign_urls_alone(): void
    {
        $service = $this->service();

        $this->assertSame(
            'https://res.cloudinary.com/democloud/image/upload/c_fill,w_400,h_250,f_auto,q_auto/v1/lessons/1/abc.jpg',
            $service->thumb('https://res.cloudinary.com/democloud/image/upload/v1/lessons/1/abc.jpg'),
        );
        $this->assertSame('/storage/lessons/1/uploads/own.jpg', $service->thumb('/storage/lessons/1/uploads/own.jpg'));
    }

    public function test_library_searches_our_own_images_and_skips_lesson_card_posters(): void
    {
        Http::fake([
            'api.cloudinary.com/*' => Http::response(['resources' => [
                ['public_id' => 'lessons/18/cover', 'secure_url' => 'https://res.cloudinary.com/democloud/image/upload/v1/lessons/18/cover.webp', 'width' => 480, 'height' => 768],
                ['public_id' => 'lessons/18/abc', 'secure_url' => 'https://res.cloudinary.com/democloud/image/upload/v1/lessons/18/abc.jpg', 'width' => 1600, 'height' => 900],
            ]]),
        ]);

        $library = $this->service()->library('golden bay');

        $this->assertCount(1, $library, 'the 480px card poster is a derivative, not library imagery');
        $this->assertSame('abc', $library[0]['title']);
        $this->assertStringContainsString('c_fill,w_400,h_250', $library[0]['thumb']);
        Http::assertSent(function ($request) {
            // Both words narrow the search, and neither can inject expression syntax.
            return str_contains($request['expression'], 'public_id:*golden*')
                && str_contains($request['expression'], 'public_id:*bay*');
        });
    }

    public function test_library_is_empty_when_cloudinary_is_not_configured(): void
    {
        config(['services.cloudinary.url' => null]);
        Http::fake();

        $this->assertSame([], (new CloudinaryService)->library());
        Http::assertNothingSent();
    }
}
