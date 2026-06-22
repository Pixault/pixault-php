<?php

declare(strict_types=1);

namespace Pixault;

/**
 * Pixault SDK client — URL generation, upload, and image management.
 *
 * Usage:
 *   $pixault = new Pixault([
 *       'base_url' => 'https://img.pixault.io',
 *       'default_project' => 'barber',
 *       'api_key' => 'pk_your_secret_key',
 *   ]);
 *
 *   // Generate URLs
 *   $url = $pixault->image('img_01JKABC')->width(800)->build();
 *
 *   // Upload
 *   $result = $pixault->upload('barber', '/path/to/photo.jpg');
 *
 *   // Metadata
 *   $meta = $pixault->getMetadata('barber', 'img_01JKABC');
 */
final class Pixault
{
    private string $baseUrl;
    private ?string $defaultProject;
    private ?string $apiKey;

    /**
     * @param array{base_url: string, default_project?: string, api_key?: string} $config
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->defaultProject = $config['default_project'] ?? null;
        $this->apiKey = $config['api_key'] ?? null;
    }

    // ── URL builder ──

    /**
     * Create a URL builder. Pass (project, imageId) or just (imageId) with a default project.
     */
    public function image(string $projectOrId, ?string $imageId = null): UrlBuilder
    {
        if ($imageId !== null) {
            return new UrlBuilder($this->baseUrl, $projectOrId, $imageId);
        }

        if ($this->defaultProject === null) {
            throw new \InvalidArgumentException(
                'default_project must be configured when calling image() without a project.'
            );
        }

        return new UrlBuilder($this->baseUrl, $this->defaultProject, $projectOrId);
    }

    // ── Upload ──

    /**
     * Upload an image file.
     *
     * @param string $project Project identifier
     * @param string $filePath Path to the image file, or '-' to use $fileContents
     * @param string|null $fileName Override the filename sent to the API
     * @param array<string, string> $metadata Optional metadata fields (name, description, etc.)
     * @param string|null $fileContents Raw file contents (used when $filePath is '-')
     * @return array{imageId: string, url: string, width: int, height: int, size: int}
     */
    public function upload(
        string $project,
        string $filePath,
        ?string $fileName = null,
        array $metadata = [],
        ?string $fileContents = null,
    ): array {
        $url = "{$this->baseUrl}/api/{$project}/upload";

        if ($filePath === '-' && $fileContents !== null) {
            $tmpFile = tmpfile();
            fwrite($tmpFile, $fileContents);
            fseek($tmpFile, 0);
            $meta = stream_get_meta_data($tmpFile);
            $tmpPath = $meta['uri'];
            $curlFile = new \CURLFile($tmpPath, '', $fileName ?? 'upload');
        } else {
            $curlFile = new \CURLFile($filePath, '', $fileName ?? basename($filePath));
        }

        $postFields = array_merge(['file' => $curlFile], $metadata);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (isset($tmpFile)) {
            fclose($tmpFile);
        }

        if ($response === false) {
            throw new PixaultException("Upload failed: {$error}");
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $message = $body['error'] ?? "Upload failed with status {$httpCode}";
            throw new PixaultException($message, $httpCode);
        }

        return json_decode($response, true);
    }

    // ── List / Search ──

    /**
     * List images with optional filtering.
     *
     * @param string $project Project identifier
     * @param array{search?: string, category?: string, keyword?: string, author?: string, isVideo?: bool} $filter
     * @param int $limit Max results (default 50)
     * @param string|null $cursor Pagination cursor
     * @return array{images: array, nextCursor: ?string, totalCount: int}
     */
    public function listImages(
        string $project,
        array $filter = [],
        int $limit = 50,
        ?string $cursor = null,
    ): array {
        $params = ['limit' => $limit];
        if ($cursor !== null) $params['cursor'] = $cursor;
        if (isset($filter['search'])) $params['search'] = $filter['search'];
        if (isset($filter['category'])) $params['category'] = $filter['category'];
        if (isset($filter['keyword'])) $params['keyword'] = $filter['keyword'];
        if (isset($filter['author'])) $params['author'] = $filter['author'];
        if (isset($filter['isVideo'])) $params['isVideo'] = $filter['isVideo'] ? 'true' : 'false';

        $url = "{$this->baseUrl}/api/{$project}/images?" . http_build_query($params);
        $response = $this->httpGet($url);

        return json_decode($response, true);
    }

    // ── Delete ──

    /** Delete an image and all its cached variants. */
    public function delete(string $project, string $imageId): void
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new PixaultException("Delete failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new PixaultException("Delete failed with status {$httpCode}", $httpCode);
        }
    }

    // ── Metadata ──

    /**
     * Get metadata for an image. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function getMetadata(string $project, string $imageId): ?array
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}/metadata";

        $response = $this->httpGet($url);
        if ($response === null) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Update metadata fields. Only provided fields are overwritten.
     *
     * @param array<string, mixed> $update
     * @return array<string, mixed>
     */
    public function updateMetadata(string $project, string $imageId, array $update): array
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}/metadata";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($update),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(
                $this->buildHeaders(),
                ['Content-Type: application/json']
            ),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new PixaultException("Metadata update failed with status {$httpCode}", $httpCode);
        }

        return json_decode($response, true);
    }

    /**
     * Get Schema.org JSON-LD for an image.
     *
     * @return array<string, mixed>|null
     */
    public function getJsonLd(string $project, string $imageId): ?array
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}/metadata/jsonld";

        $response = $this->httpGet($url);
        if ($response === null) {
            return null;
        }

        return json_decode($response, true);
    }

    // ── EPS Operations ──

    /**
     * List derived assets (rasterized PNGs, SVGs, splits) for an EPS parent.
     *
     * @return array<int, array{imageId: string, derivationType: ?string, width: int, height: int, sizeBytes: int, contentType: string, uploadedAt: string}>
     */
    public function listDerived(string $project, string $imageId): array
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}/derived";
        $response = $this->httpGet($url);
        return $response !== null ? json_decode($response, true) : [];
    }

    /**
     * Get the processing status for an EPS file. Returns null if no job found.
     *
     * @return array{id: string, source: string, status: string, totalAssets: int, processedAssets: int, succeededAssets: int, failedAssets: int, createdAt: string, startedAt: ?string, completedAt: ?string}|null
     */
    public function getProcessingStatus(string $project, string $imageId): ?array
    {
        $url = "{$this->baseUrl}/api/{$project}/{$imageId}/processing-status";
        $response = $this->httpGet($url);
        return $response !== null ? json_decode($response, true) : null;
    }

    /** Trigger auto-split to extract individual designs from an EPS file. */
    public function splitDesigns(string $project, string $imageId): void
    {
        $this->httpPost("{$this->baseUrl}/api/{$project}/{$imageId}/split");
    }

    /** Trigger vector SVG extraction from an EPS file. */
    public function extractSvg(string $project, string $imageId): void
    {
        $this->httpPost("{$this->baseUrl}/api/{$project}/{$imageId}/extract-svg");
    }

    // ── Internal ──

    private function httpGet(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404) {
            return null;
        }

        if ($httpCode >= 400) {
            throw new PixaultException("Request failed with status {$httpCode}", $httpCode);
        }

        return $response !== false ? $response : null;
    }

    private function httpPost(string $url): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new PixaultException("Request failed: {$error}");
        }

        if ($httpCode >= 400) {
            $body = json_decode($response, true);
            $message = $body['error'] ?? "Request failed with status {$httpCode}";
            throw new PixaultException($message, $httpCode);
        }
    }

    /** @return string[] */
    private function buildHeaders(): array
    {
        $headers = [];
        if ($this->apiKey !== null) {
            $headers[] = "X-Api-Key: {$this->apiKey}";
        }
        return $headers;
    }
}
