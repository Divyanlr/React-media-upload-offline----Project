<?php
namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;

/*
  Robust ChunkHandler
  - ensures session folder exists on initiate/store
  - assemble using stream copy (safe for large files)
  - writes assembled.json marker to make finalize idempotent
  - returns clear exceptions/messages for client debugging
*/

class ChunkHandler
{
    private string $projectDir;
    private Filesystem $fs;

    public function __construct(string $projectDir)
    {
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->fs = new Filesystem();
    }

    /**
     * Create the chunk session folder and save meta.
     * Throws exception on failure.
     */
    public function initSession(string $uploadId, array $meta = []): void
    {
        $folder = $this->getChunkFolder($uploadId);

        if (!$this->fs->exists($folder)) {
            try {
                $this->fs->mkdir($folder, 0755);
            } catch (\Throwable $e) {
                throw new \RuntimeException("failed_create_session_folder: " . $e->getMessage());
            }
        }

        // save meta (overwrite if present)
        $metaPath = $folder . '/meta.json';
        try {
            file_put_contents($metaPath, json_encode($meta));
        } catch (\Throwable $e) {
            throw new \RuntimeException("failed_save_meta: " . $e->getMessage());
        }
    }

    /**
     * Store an uploaded chunk.
     * Accepts Symfony UploadedFile or similar object with move() method.
     */
    public function storeChunk(string $uploadId, int $index, $uploadedFile): void
    {
        $folder = $this->getChunkFolder($uploadId);

        // defensive: if session folder missing, create it instead of failing
        if (!$this->fs->exists($folder)) {
            try {
                $this->fs->mkdir($folder, 0755);
            } catch (\Throwable $e) {
                throw new \RuntimeException("failed_create_session_folder_on_store: " . $e->getMessage());
            }
        }

        $destName = sprintf('%05d.chunk', $index);
        $destPath = $folder . DIRECTORY_SEPARATOR . $destName;

        try {
            // If $uploadedFile is a Symfony UploadedFile, use move()
            if (is_object($uploadedFile) && method_exists($uploadedFile, 'move')) {
                $uploadedFile->move($folder, $destName);
            } else {
                // fallback: if it's an array or plain stream, try to write content
                if (is_string($uploadedFile)) {
                    // string content provided
                    file_put_contents($destPath, $uploadedFile);
                } elseif (is_resource($uploadedFile)) {
                    $out = fopen($destPath, 'wb');
                    stream_copy_to_stream($uploadedFile, $out);
                    fclose($out);
                } else {
                    // attempt to read tmp_name if present (PSR file array)
                    $tmp = $uploadedFile->getRealPath() ?? ($uploadedFile->tmp_name ?? null);
                    if ($tmp && file_exists($tmp)) {
                        copy($tmp, $destPath);
                    } else {
                        throw new \RuntimeException("unsupported_uploaded_file_type");
                    }
                }
            }
            // update timestamp for cleanup tolerance
            @touch($destPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException("chunk_store_error: " . $e->getMessage());
        }
    }

    /**
     * Assemble chunks into final file. Returns array with path/md5/mime.
     * Idempotent: writes assembled.json marker and returns it on repeated calls.
     */
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

        // gather chunk files
        $all = array_values(array_filter(scandir($folder), function($n){
            return preg_match('/\.chunk$/', $n);
        }));
        sort($all, SORT_STRING);

        if (empty($all)) {
            throw new \RuntimeException('no_chunks_found');
        }

        // ensure final directory exists
        $dateFolder = date('Y-m-d');
        $finalDir = $this->projectDir . '/public/uploads/final/' . $dateFolder;
        if (!$this->fs->exists($finalDir)) {
            try { $this->fs->mkdir($finalDir, 0755); } catch (\Throwable $e) {
                throw new \RuntimeException('failed_create_final_dir: ' . $e->getMessage());
            }
        }

        // create temp file safely
        $tempFile = tempnam(sys_get_temp_dir(), 'assemble_');
        if ($tempFile === false) {
            throw new \RuntimeException('failed_create_tempfile');
        }

        $out = fopen($tempFile, 'ab');
        if ($out === false) {
            @unlink($tempFile);
            throw new \RuntimeException('failed_open_tempfile');
        }

        try {
            foreach ($all as $chunkFile) {
                $chunkPath = $folder . DIRECTORY_SEPARATOR . $chunkFile;
                if (!is_readable($chunkPath)) {
                    fclose($out);
                    @unlink($tempFile);
                    throw new \RuntimeException('chunk_not_readable:' . $chunkFile);
                }
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);
                    @unlink($tempFile);
                    throw new \RuntimeException('chunk_open_failed:' . $chunkFile);
                }
                // stream copy
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fflush($out);
            fclose($out);
        } catch (\Throwable $e) {
            if (is_resource($out)) fclose($out);
            @unlink($tempFile);
            throw new \RuntimeException('assemble_stream_error: ' . $e->getMessage());
        }

        // basic mime detection by magic bytes
        $mime = $this->detectMimeFromFile($tempFile);
        if (!$this->isAllowedMime($mime)) {
            @unlink($tempFile);
            throw new \RuntimeException('invalid_file_type:' . $mime);
        }

        // compute md5 and name
        $md5 = md5_file($tempFile);
        if ($md5 === false) {
            @unlink($tempFile);
            throw new \RuntimeException('md5_failed');
        }

        $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: $this->extensionFromMime($mime);
        $safeBase = preg_replace('/[^A-Za-z0-9\-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $finalName = sprintf('%s_%s.%s', $safeBase, substr($md5,0,8), $ext);
        $finalPath = $finalDir . DIRECTORY_SEPARATOR . $finalName;

        // move temp to final path; if rename fails (cross-filesystem), copy & unlink
        try {
            if (!@rename($tempFile, $finalPath)) {
                if (!@copy($tempFile, $finalPath)) {
                    @unlink($tempFile);
                    throw new \RuntimeException('failed_move_to_final');
                }
                @unlink($tempFile);
            }
        } catch (\Throwable $e) {
            @unlink($tempFile);
            throw new \RuntimeException('finalize_move_error: ' . $e->getMessage());
        }

        // write assembled marker
        $result = [
            'path' => '/uploads/final/' . $dateFolder . '/' . basename($finalPath),
            'md5' => $md5,
            'mime' => $mime,
            'size' => filesize($finalPath)
        ];
        try {
            file_put_contents($assembledMarker, json_encode($result));
        } catch (\Throwable $e) {
            // non-fatal marker write failure — still return result
        }

        // cleanup chunk folder (best-effort)
        try {
            $this->fs->remove($folder);
        } catch (\Throwable $e) {
            // ignore cleanup failures
        }

        return $result;
    }

    /**
     * Return basic status about the session/chunks
     */
    public function getStatus(string $uploadId): array
    {
        $folder = $this->getChunkFolder($uploadId);
        if (!$this->fs->exists($folder)) return ['exists' => false, 'chunks' => 0];
        $chunks = array_values(array_filter(scandir($folder), function($n){ return preg_match('/\.chunk$/', $n); }));
        return ['exists' => true, 'chunks' => count($chunks)];
    }

    private function getChunkFolder(string $uploadId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $uploadId);
        return $this->projectDir . '/public/uploads/chunks/' . $safe;
    }

    private function detectMimeFromFile(string $filePath): string
    {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) return 'application/octet-stream';
        $bytes = fread($fh, 16);
        fclose($fh);

        if (substr($bytes, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
        if (substr($bytes, 0, 8) === "\x89PNG\x0D\x0A\x1A\x0A") return 'image/png';
        if (strpos($bytes, 'ftyp') !== false) return 'video/mp4';
        if (stripos($bytes, 'webm') !== false || stripos($bytes, 'WEBM') !== false) return 'video/webm';
        return 'application/octet-stream';
    }

    private function isAllowedMime(string $mime): bool
    {
        $allowed = ['image/jpeg','image/png','video/mp4','video/webm'];
        return in_array($mime, $allowed, true);
    }

    private function extensionFromMime(string $mime): string
    {
        $map = ['image/jpeg'=>'jpg','image/png'=>'png','video/mp4'=>'mp4','video/webm'=>'webm'];
        return $map[$mime] ?? 'bin';
    }
}
