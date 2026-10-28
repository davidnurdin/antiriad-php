<?php

namespace JumPHP\Tests\Gl;

use JumPHP\Gl\Mat4;
use JumPHP\Gl\WebGl;
use JumPHP\Bridge\JsBridge;
use PHPUnit\Framework\TestCase;

final class Mat4Test extends TestCase
{
    public function testMultiplyOrderAndTransform(): void
    {
        // translate after scale: (1,0,0) -> scaled to (2,0,0) -> moved to (3,0,0)
        $m = Mat4::multiply(Mat4::translate(1, 0, 0), Mat4::scale(2));
        self::assertEqualsWithDelta([3.0, 0.0, 0.0, 1.0], Mat4::transform($m, [1, 0, 0]), 1e-9);
        self::assertEqualsWithDelta(Mat4::identity(), Mat4::multiply(Mat4::identity(), Mat4::identity()), 1e-9);
        self::assertEqualsWithDelta([0.0, 0.0, -1.0, 1.0], Mat4::transform(Mat4::rotateY(M_PI / 2), [1, 0, 0]), 1e-9);
    }

    public function testProjection(): void
    {
        $mvp = Mat4::multiply(Mat4::perspective(90, 1, 0.1, 100), Mat4::lookAt([0, 0, 5], [0, 0, 0]));
        // the target is the centre of the view
        self::assertEqualsWithDelta([160.0, 90.0], array_slice(Mat4::project($mvp, [0, 0, 0], 320, 180), 0, 2), 1e-6);
        // up is up on screen
        self::assertLessThan(90, Mat4::project($mvp, [0, 1, 0], 320, 180)[1]);
        // behind the camera
        self::assertNull(Mat4::project($mvp, [0, 0, 10], 320, 180));
    }

    public function testWebGlDoesNothingOnDesktop(): void
    {
        $gl = new WebGl(new JsBridge());
        self::assertFalse($gl->isAvailable());
        $gl->setLayer();
        $gl->draw($gl->program('a', 'b'), ['count' => 3]);
        self::assertFalse(is_file(WebGl::FILE) && filemtime(WebGl::FILE) >= time() - 1);
    }
}
