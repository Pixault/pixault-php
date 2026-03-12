<?php

declare(strict_types=1);

namespace Pixault;

/**
 * Fluent builder for Pixault image transformation URLs.
 *
 * Usage:
 *   $url = $pixault->image('tattoo', 'img_01JKABC')
 *       ->transform('gallery')
 *       ->width(800)
 *       ->build();
 *   // => "https://img.pixault.io/tattoo/img_01JKABC/t_gallery,w_800.webp"
 */
final class UrlBuilder
{
    private string $baseUrl;
    private string $project;
    private string $imageId;
    /** @var string[] */
    private array $params = [];
    private string $format = 'webp';
    private ?string $transformName = null;

    /** @internal Use Pixault::image() instead. */
    public function __construct(string $baseUrl, string $project, string $imageId)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->project = $project;
        $this->imageId = $imageId;
    }

    /** Apply a named transform preset. */
    public function transform(string $name): self
    {
        $this->transformName = $name;
        return $this;
    }

    /** Set the output width in pixels. */
    public function width(int $w): self
    {
        $this->params[] = "w_{$w}";
        return $this;
    }

    /** Set the output height in pixels. */
    public function height(int $h): self
    {
        $this->params[] = "h_{$h}";
        return $this;
    }

    /** Set the resize fit mode: cover, contain, fill, or pad. */
    public function fit(string $mode): self
    {
        $this->params[] = "fit_{$mode}";
        return $this;
    }

    /** Set the output quality (1-100). */
    public function quality(int $q): self
    {
        $this->params[] = "q_{$q}";
        return $this;
    }

    /** Apply a Gaussian blur with the given radius. */
    public function blur(int $radius): self
    {
        $this->params[] = "blur_{$radius}";
        return $this;
    }

    /** Apply a watermark overlay. */
    public function watermark(string $id, string $position = 'br', int $opacity = 30): self
    {
        $this->params[] = "wm_{$id}";
        $this->params[] = "wm_pos_{$position}";
        $this->params[] = "wm_opacity_{$opacity}";
        return $this;
    }

    /** Set the output format. Default is "webp". */
    public function format(string $fmt): self
    {
        $this->format = ltrim($fmt, '.');
        return $this;
    }

    /** Build the final CDN URL. */
    public function build(): string
    {
        $allParams = [];

        if ($this->transformName !== null) {
            $allParams[] = "t_{$this->transformName}";
        }

        $allParams = array_merge($allParams, $this->params);

        if (count($allParams) === 0) {
            return "{$this->baseUrl}/{$this->project}/{$this->imageId}/original.{$this->format}";
        }

        $joined = implode(',', $allParams);
        return "{$this->baseUrl}/{$this->project}/{$this->imageId}/{$joined}.{$this->format}";
    }

    public function __toString(): string
    {
        return $this->build();
    }
}
