<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Exif;
use OCA\Memories\Tests\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Exif::getBurstId
 */
final class ExifBurstIdTest extends TestCase
{
    public function testAppleBurstUuidWins(): void
    {
        self::assertSame('apple:abcdef12-3456', Exif::getBurstId('IMG_0001_BURST20201231123456789.JPG', ['BurstUUID' => 'ABCDEF12-3456']));
    }

    public function testGoogleAndSonyShareTimestamp(): void
    {
        $id = 'burst:20201231123456789';
        self::assertSame($id, Exif::getBurstId('00000IMG_00000_BURST20201231123456789_COVER.jpg', []));
        self::assertSame($id, Exif::getBurstId('00001IMG_00001_BURST20201231123456789.jpg', []));
        self::assertSame('burst:20180101120000123', Exif::getBurstId('DSC_0001_BURST20180101120000123.JPG', []));
    }

    public function testHuawei(): void
    {
        self::assertSame('huawei:IMG_20210101_120000', Exif::getBurstId('IMG_20210101_120000_BURST001_COVER.jpg', []));
        self::assertSame('huawei:IMG_20210101_120000', Exif::getBurstId('IMG_20210101_120000_BURST002.jpg', []));
    }

    public function testSamsung(): void
    {
        self::assertSame('samsung:20230812_143012', Exif::getBurstId('20230812_143012_001.jpg', []));
    }

    public function testPlainPhotosAreNotBursts(): void
    {
        self::assertNull(Exif::getBurstId('20230812_143012.jpg', []));
        self::assertNull(Exif::getBurstId('IMG_1234.HEIC', []));
        self::assertNull(Exif::getBurstId('PXL_20231004_123456789.jpg', ['BurstUUID' => '']));
    }
}
