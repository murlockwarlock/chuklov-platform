<?php

namespace Tests\Unit\Feedback;

use App\Modules\Feedback\Application\ReviewDestinationIconResolver;
use Tests\TestCase;

final class ReviewDestinationIconResolverTest extends TestCase
{
    public function test_known_review_platform_hosts_receive_safe_portal_icon_keys(): void
    {
        $resolver = new ReviewDestinationIconResolver;

        self::assertSame('map', $resolver->resolve('https://reviews.2gis.ru/almaty'));
        self::assertSame('pin', $resolver->resolve('https://maps.google.com/?cid=123'));
        self::assertSame('compass', $resolver->resolve('https://yandex.ru/maps/org/example'));
    }

    public function test_unknown_and_lookalike_hosts_use_the_generic_icon(): void
    {
        $resolver = new ReviewDestinationIconResolver;

        self::assertSame('globe', $resolver->resolve('https://reviews.example.test/chuklov'));
        self::assertSame('globe', $resolver->resolve('https://google.com.example.test/review'));
    }
}
