<?php

namespace Tests\Fixtures;

use Illuminate\Http\UploadedFile;

trait CreatesInspectionPhotos
{
    private function photo(string $name = 'photo.png', int $width = 64, int $height = 64): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $pixels = str_repeat("\0".str_repeat("\0", 64 * 3), 64);
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($pixels)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $png);
    }
}
