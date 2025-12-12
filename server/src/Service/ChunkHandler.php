<?php
namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;

/**
 * ChunkHandler
 *
 * Responsibilities:
 * - create session folder on initiate
 * - store chunk files: {index}.chunk
 * - assemble into final file on finalize (idempotent)
 * - status: list chunks present / expected
 *
 * Note: this implementation uses filesystem only and is suitable for local dev/test.
 * For production add Redis tracking, concurrency locks, magic number checks, and cleanup cron.
 */
class ChunkHandler
{
    private string $projectDir;
    private Filesystem $fs;
    private string $tmpRoot;

    public function __construct(string $projectDir)
    {
        $this->projectDir = rtrim($projectDir, "/");
        $this->fs = new Filesystem();
        $this->tmpRoot = $this->projectDir . '/var/uploads_chunks';
        if (!$this->fs->exists($this->tmpRoot)) {
            $this->fs->mkdir($this->tmpRoot, 0755);
        }
    }

    // create session folder and write meta
    public function initSession(string $uploadId, string $fileName, int $size, ?string $mime = null, ?int $totalChunks = null): void
    {
        $folder = $this->getChunkFolder($uploadId);
        if (!$this->fs->exists($folder)) {
            $this->fs->mkdir($folder, 0755);
        }

        $meta = [
            'uploadId' => $uploadId,
            'fileName' => $fileName,
            'size' => $size,
            'mime' => $mime,
            'totalChunks' => $totalChunks,
            'createdAt' => time()
        ];

        file_put_contents($folder . '/meta.json', json_encode($meta));
    }

    // store an uploaded chunk file. $tmpPath is a temporary path (move/copy)
    public function storeChunk(string $uploadId, int $index, string $tmpPath): void
    {
        $folder = $this->getChunkFolder($uploadId);
        if (!$this->fs->exists($folder)) {
            throw new \RuntimeException('session_not_found');
        }

        $target = $folder . '/' . sprintf('%06d.chunk', $index);

        // Move uploaded tmp file to target
        if (!@rename($tmpPath, $target)) {
            // fallback to copy + unlink
            if (!@copy($tmpPath, $target)) {
                throw new \RuntimeException('chunk_store_failed');
            }
            @unlink($tmpPath);
        }
    }

    // assemble chunks into final file
    public function assembleChunks(string $uploadId, string $originalName): array
    {
        $folder = $this->getChunkFolder($uploadId);
        if (!$this->fs->exists($folder)) {
            throw new \RuntimeException('session_not_found');
        }

        $assembledMarker = $folder . '/assembled.json';
        if (file_exists($assembledMarker)) {
            $data = json_decode(file_get_contents($assembledMarker), true);
            if (is_array($data)) {
                return $data;
            }
        }

        // read meta if present
        $meta = [];
        if (file_exists($folder . '/meta.json')) {
            $meta = json_decode(file_get_contents($folder . '/meta.json'), true) ?: [];
        }

        $chunks = array_values(array_filter(scandir($folder), function ($n) {
            return preg_match('/\.chunk$/', $n);
        }));
        sort($chunks, SORT_STRING);

        if (empty($chunks)) {
            throw new \RuntimeException('no_chunks_found');
        }

        // final paths
        $dateFolder = date('Y-m-d');
        $finalDir = $this->projectDir . '/public/uploads/final/' . $dateFolder;
        if (!$this->fs->exists($finalDir)) {
            $this->fs->mkdir($finalDir, 0755);
        }

        // sanitize name and ensure unique
        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($originalName));
        $finalPath = $finalDir . '/' . time() . '_' . $safeName;

        // assemble via streaming append
        $out = fopen($finalPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('final_open_failed');
        }

        try {
            foreach ($chunks as $c) {
                $chunkPath = $folder . '/' . $c;
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);
                    throw new \RuntimeException('chunk_missing: ' . $c);
                }
                while (!feof($in)) {
                    $buf = fread($in, 8192);
                    if ($buf === false) {
                        fclose($in);
                        fclose($out);
                        throw new \RuntimeException('chunk_read_error');
                    }
                    fwrite($out, $buf);
                }
                fclose($in);
            }
            fflush($out);
            fclose($out);
        } catch (\Throwable $e) {
            @fclose($out);
            @unlink($finalPath);
            throw new \RuntimeException('finalize_move_error: ' . $e->getMessage());
        }

        // compute md5 and mime fallback
        $md5 = md5_file($finalPath) ?: '';
        $mime = $meta['mime'] ?? mime_content_type($finalPath);

        $result = [
            'path' => '/uploads/final/' . $dateFolder . '/' . basename($finalPath),
            'md5' => $md5,
            'mime' => $mime,
            'size' => filesize($finalPath)
        ];

        // try write assembled marker
        try {
            file_put_contents($assembledMarker, json_encode($result));
        } catch (\Throwable $e) {
            // non-fatal
        }

        // best-effort cleanup of chunk folder (keep assembled marker for idempotence)
        try {
            $files = array_diff(scandir($folder), ['.', '..', 'assembled.json', 'meta.json']);
            foreach ($files as $f) {
                @unlink($folder . '/' . $f);
            }
            // keep folder with marker
        } catch (\Throwable $e) {
            // ignore cleanup failures
        }

        return $result;
    }

    // status returns which chunk files exist and metadata
    public function getStatus(string $uploadId): array
    {
        $folder = $this->getChunkFolder($uploadId);
        if (!$this->fs->exists($folder)) {
            return ['state' => 'not_found'];
        }

        $meta = [];
        if (file_exists($folder . '/meta.json')) {
            $meta = json_decode(file_get_contents($folder . '/meta.json'), true) ?: [];
        }

        $chunks = array_values(array_filter(scandir($folder), function ($n) {
            return preg_match('/\.chunk$/', $n);
        }));
        sort($chunks, SORT_STRING);

        return [
            'state' => file_exists($folder . '/assembled.json') ? 'assembled' : 'active',
            'meta' => $meta,
            'chunks' => $chunks,
            'count' => count($chunks)
        ];
    }

    private function getChunkFolder(string $uploadId): string
    {
        // sanitize
        $id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $uploadId);
        return $this->tmpRoot . '/' . $id;
    }
}
