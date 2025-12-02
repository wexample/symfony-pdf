<?php

namespace Wexample\SymfonyPdf\Service\Pdf\Page;

use function array_slice;
use function count;
use function floor;

use JetBrains\PhpStorm\Pure;
use TCPDF;

abstract class Items extends Page
{
    public $itemHeight = 20;

    public $yListEnd;

    public float $listTotalHeight = 30;

    public string $listClass = Items::class;

    public function __construct(
        protected array $items = []
    ) {

    }

    public function renderBody(TCPDF $pdf)
    {
        $items = $this->getItems();

        if (! empty($items)) {
            $this->renderListHeader($pdf);
        }

        $document = $this->getPdfService();
        $y = $this->y;
        $areaHeight = $this->getYEnd() - $y;
        $maxItems = floor($areaHeight / $this->itemHeight);

        $rest = array_slice($items, $maxItems);
        $items = array_slice($items, 0, $maxItems);
        $end = ! count($rest);

        foreach ($items as $item) {
            $this->renderItem($pdf, $item, $y);
            // Item height does not vary.
            $y += $this->itemHeight;
        }

        if (! $end) {
            // New page.
            /** @var Items $page */
            $page = new $this->listClass($rest);
            $page->setPdfService($document);
            $page->render($pdf);
        } elseif ($y > $this->getYEnd($this->listTotalHeight)) {
            /** @var Items $page */
            $page = new $this->listClass();
            $page->setPdfService($document);
            $page->render($pdf);
        } else {
            $this->renderTotalBlock($pdf);
        }
    }

    abstract public function getItems(): array;

    abstract public function renderListHeader(TCPDF $pdf);

    #[Pure]
    public function getYEnd($footerExtraSection = 0)
    {
        $document = $this->getPdfService();

        // Default margin.
        return $this->yListEnd ?:
            $document->pageHeight -
            $document->footerHeight -
            $footerExtraSection;
    }

    abstract public function renderItem(TCPDF $pdf, $item, float $y);

    abstract public function renderTotalBlock(TCPDF $pdf);
}
