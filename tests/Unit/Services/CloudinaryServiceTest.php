<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CloudinaryService;
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
}
