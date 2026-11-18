<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Template;

use Hryvinskyi\ThemeAssets\Api\AssetRendererInterface;
use Magento\Framework\View\Asset\File;

/**
 * An asset renderer that records every request a template makes and prints one marker element per requested file,
 * carrying the file id, render type and load strategy
 */
class RecordingAssetRenderer implements AssetRendererInterface
{
    /**
     * @var list<array{
     *     kind: string,
     *     fileId: string,
     *     renderType: string,
     *     loadStrategy: string|null,
     *     preload: bool,
     *     options: array<mixed>
     * }> Every render request, in order
     */
    public array $calls = [];

    /**
     * Refuse: a template asks for rendered tags, never builds assets
     *
     * @param string $fileId
     * @param array<mixed> $params
     * @return File
     * @throws \LogicException Always
     */
    public function createAsset(string $fileId, array $params = []): File
    {
        throw new \LogicException('The template must not create assets itself.');
    }

    /**
     * @inheritDoc
     */
    public function renderScript(
        string $fileId,
        string $renderType = self::RENDER_INLINE,
        string $loadStrategy = self::LOAD_SYNC,
        bool $preload = self::PRELOAD_DISABLED,
        array $params = [],
        array $options = []
    ): string {
        $this->calls[] = [
            'kind' => 'script',
            'fileId' => $fileId,
            'renderType' => $renderType,
            'loadStrategy' => $loadStrategy,
            'preload' => $preload,
            'options' => $options,
        ];

        return sprintf(
            '<script data-marker-file-id="%s" data-marker-render="%s" data-marker-load="%s"></script>',
            htmlspecialchars($fileId, ENT_QUOTES),
            htmlspecialchars($renderType, ENT_QUOTES),
            htmlspecialchars($loadStrategy, ENT_QUOTES)
        );
    }

    /**
     * Refuse: the beacon template renders no stylesheet
     *
     * @param string $fileId
     * @param string $renderType
     * @param bool $preload
     * @param array<mixed> $params
     * @param array<mixed> $options
     * @return string
     * @throws \LogicException Always
     */
    public function renderStyle(
        string $fileId,
        string $renderType = self::RENDER_INLINE,
        bool $preload = self::PRELOAD_DISABLED,
        array $params = [],
        array $options = []
    ): string {
        throw new \LogicException('The template must not render a stylesheet.');
    }

    /**
     * Refuse: a template asks for rendered tags, never reads asset content
     *
     * @param string $fileId
     * @param array<mixed> $params
     * @return string
     * @throws \LogicException Always
     */
    public function getContent(string $fileId, array $params = []): string
    {
        throw new \LogicException('The template must not read asset content itself.');
    }
}
