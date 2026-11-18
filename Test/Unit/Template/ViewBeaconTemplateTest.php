<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\CatalogSortingMostViewed\Test\Unit\Template;

use Hryvinskyi\CatalogSortingMostViewed\ViewModel\ProductViewBeacon;
use Hryvinskyi\ThemeAssets\Api\AssetRendererInterface;
use Magento\Catalog\Block\Product\View;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Renders the view beacon template with the block class the layout declares, a stub view model and a recording
 * asset renderer: where the page has a beacon it prints the hidden element with the escaped endpoint URL and
 * product id and asks for the beacon script as an external deferred file without preload; otherwise it prints and
 * requests nothing.
 */
class ViewBeaconTemplateTest extends TestCase
{
    private const TEMPLATE = '/view/frontend/templates/product/view-beacon.phtml';
    private const SCRIPT = 'Hryvinskyi_CatalogSortingMostViewed::js/product-view-beacon.js';

    /**
     * An endpoint URL holding characters an attribute must escape
     */
    private const URL = 'https://shop.test/hryvinskyi_sorting/view/count/?a=1&b="x"';

    /**
     * @var RecordingAssetRenderer
     */
    private RecordingAssetRenderer $renderer;

    /**
     * @var Product&MockObject
     */
    private $product;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->renderer = new RecordingAssetRenderer();
        $this->product = $this->createMock(Product::class);
    }

    /**
     * @return void
     */
    public function testPrintsTheBeaconElementAndAsksForTheDeferredExternalScript(): void
    {
        $viewModel = $this->viewModel(['url' => self::URL, 'product' => 42]);

        $html = $this->render($viewModel, $this->renderer);

        $element = $this->beaconElement($html);
        self::assertNotNull($element);
        self::assertSame(self::URL, $element->getAttribute('data-url'));
        self::assertSame('42', $element->getAttribute('data-product'));
        self::assertTrue($element->hasAttribute('hidden'));
        self::assertStringNotContainsString('"x"', $html, 'The URL is escaped inside its attribute.');

        self::assertCount(1, $this->renderer->calls);
        self::assertSame('script', $this->renderer->calls[0]['kind']);
        self::assertSame(self::SCRIPT, $this->renderer->calls[0]['fileId']);
        self::assertSame(AssetRendererInterface::RENDER_EXTERNAL, $this->renderer->calls[0]['renderType']);
        self::assertSame(AssetRendererInterface::LOAD_DEFER, $this->renderer->calls[0]['loadStrategy']);
        self::assertFalse($this->renderer->calls[0]['preload']);
        self::assertSame([self::SCRIPT], $this->printedScripts($html));
    }

    /**
     * @return void
     */
    public function testAsksTheViewModelForTheBeaconOfTheBlocksProduct(): void
    {
        $viewModel = $this->createMock(ProductViewBeacon::class);
        $viewModel->expects(self::once())->method('getBeacon')->with($this->product)->willReturn(null);

        $this->render($viewModel, $this->renderer);
    }

    /**
     * @return void
     */
    public function testPrintsNothingWhereThePageHasNoBeacon(): void
    {
        $html = $this->render($this->viewModel(null), $this->renderer);

        self::assertSame('', trim($html));
        self::assertSame([], $this->renderer->calls);
    }

    /**
     * @return void
     */
    public function testPrintsNothingWithoutTheViewModel(): void
    {
        $html = $this->render(null, $this->renderer);

        self::assertSame('', trim($html));
        self::assertSame([], $this->renderer->calls);
    }

    /**
     * @return void
     */
    public function testPrintsNothingWhenTheAssetRendererArgumentIsNotARenderer(): void
    {
        $html = $this->render($this->viewModel(['url' => self::URL, 'product' => 42]), new \stdClass());

        self::assertSame('', trim($html));
    }

    /**
     * A stub view model answering the given beacon
     *
     * @param array{url: string, product: int}|null $beacon
     * @return ProductViewBeacon
     */
    private function viewModel(?array $beacon): ProductViewBeacon
    {
        $viewModel = $this->createMock(ProductViewBeacon::class);
        $viewModel->method('getBeacon')->willReturn($beacon);

        return $viewModel;
    }

    /**
     * Render the template the way the product view block does, with the given block arguments
     *
     * @param object|null $viewModel The view_model argument
     * @param object $assetRenderer The asset_renderer argument
     * @return string Printed HTML
     */
    private function render(?object $viewModel, object $assetRenderer): string
    {
        $arguments = ['view_model' => $viewModel, 'asset_renderer' => $assetRenderer];
        $block = $this->createMock(View::class);
        $block->method('getData')->willReturnCallback(
            static fn (string $key = ''): mixed => $arguments[$key] ?? null
        );
        $block->method('getProduct')->willReturn($this->product);
        $escaper = $this->escaper();

        $render = static function (string $file, View $block, Escaper $escaper): void {
            include $file;
        };

        ob_start();
        try {
            $render($this->moduleFile(self::TEMPLATE), $block, $escaper);
        } finally {
            $html = ob_get_clean();
        }

        return is_string($html) ? $html : '';
    }

    /**
     * Find the beacon element in printed HTML
     *
     * @param string $html
     * @return \DOMElement|null
     */
    private function beaconElement(string $html): ?\DOMElement
    {
        $nodes = $this->parse($html)->query('//*[@data-hryvinskyi-view-beacon]');
        self::assertNotFalse($nodes);
        self::assertCount(1, $nodes);
        $node = $nodes->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    /**
     * The file ids of the printed script markers, in document order
     *
     * @param string $html
     * @return list<string>
     */
    private function printedScripts(string $html): array
    {
        $fileIds = [];
        $nodes = $this->parse($html)->query('//script[@data-marker-file-id]');
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof \DOMElement) {
                $fileIds[] = $node->getAttribute('data-marker-file-id');
            }
        }

        return $fileIds;
    }

    /**
     * Parse printed HTML into a document
     *
     * @param string $html
     * @return \DOMXPath
     */
    private function parse(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>'
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    /**
     * A real escaper with inline translation off
     *
     * @return Escaper
     */
    private function escaper(): Escaper
    {
        $translateInline = $this->createMock(InlineInterface::class);
        $translateInline->method('isAllowed')->willReturn(false);
        $objectManager = new ObjectManager($this);
        $escaper = $objectManager->getObject(Escaper::class);
        self::assertInstanceOf(Escaper::class, $escaper);
        $objectManager->setBackwardCompatibleProperty($escaper, 'escaper', new ZendEscaper());
        $objectManager->setBackwardCompatibleProperty($escaper, 'translateInline', $translateInline);

        return $escaper;
    }

    /**
     * The absolute path of a file of the module
     *
     * @param string $path Path inside the module, starting with a slash
     * @return string
     */
    private function moduleFile(string $path): string
    {
        return dirname(__DIR__, 3) . $path;
    }
}
