<?php
declare(strict_types=1);

function nmsFullContentApiPath(string $url): string {
    $url = trim($url);
    if ($url === '') throw new RuntimeException('NMS initial load content URL is empty.');

    $path = preg_match('#^https?://#i', $url)
        ? (string)parse_url($url, PHP_URL_PATH)
        : $url;

    if (!str_starts_with($path, '/')) $path = '/' . $path;
    if (str_starts_with($path, '/nmsapi/v1/')) return substr($path, strlen('/nmsapi/v1'));
    if (str_starts_with($path, '/v1/')) return substr($path, strlen('/v1'));
    if (str_starts_with($path, '/content/')) return $path;
    throw new RuntimeException('Unexpected NMS initial load content path.');
}

function nmsFullWritePayload(string $payload, string $path): void {
    if (@file_put_contents($path, $payload, LOCK_EX) === false) {
        throw new RuntimeException('NMS initial load temporary file could not be written.');
    }
}

function nmsFullMaterializeXml(string $inputPath): string {
    $fh = @fopen($inputPath, 'rb');
    if (!$fh) throw new RuntimeException('NMS initial load temporary file could not be opened.');
    $magic = fread($fh, 4);
    fclose($fh);

    $xmlPath = $inputPath . '.xml';

    if (substr($magic, 0, 2) === "\x1f\x8b") {
        if (!function_exists('gzopen')) throw new RuntimeException('PHP zlib extension is required for NMS gzip content.');
        $in = gzopen($inputPath, 'rb');
        $out = fopen($xmlPath, 'wb');
        if (!$in || !$out) throw new RuntimeException('NMS gzip file could not be opened.');
        while (!gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) break;
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
        return $xmlPath;
    }

    if ($magic === "PK\x03\x04") {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP ZipArchive extension is required for NMS zip content.');
        $zip = new ZipArchive();
        if ($zip->open($inputPath) !== true) throw new RuntimeException('NMS zip file could not be opened.');
        $stream = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && !str_ends_with($name, '/')) {
                $stream = $zip->getStream($name);
                break;
            }
        }
        if (!$stream) {
            $zip->close();
            throw new RuntimeException('NMS zip did not contain a readable file.');
        }
        $out = fopen($xmlPath, 'wb');
        if (!$out) {
            fclose($stream);
            $zip->close();
            throw new RuntimeException('NMS XML temporary file could not be created.');
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        $zip->close();
        return $xmlPath;
    }

    if (!@copy($inputPath, $xmlPath)) {
        throw new RuntimeException('NMS initial load XML could not be materialized.');
    }
    return $xmlPath;
}
