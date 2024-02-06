<?php

namespace Wexample\SymfonyPdf\Service\Pdf\Page;

use App\Pdf\PdfDocument;
use JetBrains\PhpStorm\ArrayShape;
use TCPDF;
use Wexample\SymfonyHelpers\Helper\DomHelper;
use Wexample\SymfonyPdf\Service\Pdf\AbstractPdfService;
use function implode;
use function is_file;
use function str_repeat;
use function str_replace;
use function str_split;
use function strtoupper;
use function substr;

abstract class Page
{
    public float $y = 0;

    public float $fontSize = 12;

    public string $fontWeight = '';

    public string $textAlign = '';

    public int $letterSpacing = 0;

    public bool $capitalize = false;

    public ?PdfDocument $document = null;

    protected AbstractPdfService $pdfService;

    protected const RENDER_OPTION_ALIGN = 'align';

    protected const RENDER_OPTION_ATTR = 'attr';

    protected const RENDER_OPTION_CLASS_BOLD = 'bold';

    protected const RENDER_OPTION_CAPITALIZE = 'capitalize';

    protected const RENDER_OPTION_CLASS = 'class';

    protected const RENDER_OPTION_CONTENT = 'content';

    protected const RENDER_OPTION_FONT_SIZE = 'fontSize';

    protected const RENDER_OPTION_FONT_WEIGHT = 'fontWeight';

    protected const RENDER_OPTION_HEIGHT = 'height';

    protected const RENDER_OPTION_LENGTH = 'length';

    protected const RENDER_OPTION_LETTER_SPACING = 'letterSpacing';

    protected const RENDER_OPTION_NBSP = 'nbsp';

    protected const RENDER_OPTION_SEPARATOR = 'separator';

    protected const RENDER_OPTION_SHORT = 'short';

    protected const RENDER_OPTION_START = 'start';

    protected const RENDER_OPTION_STYLE = 'style';

    protected const RENDER_OPTION_TEXT_ALIGN = 'textAlign';

    protected const RENDER_OPTION_TEXT_ALIGN_RIGHT = 'right';

    protected const RENDER_OPTION_WIDTH = 'width';

    public function render(TCPDF $pdf)
    {
        // Remove unexpected border on page bottom.
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);

        $pdf->AddPage();

        $this->renderHeader($pdf);
        $this->renderBody($pdf);
        $this->renderFooter($pdf);
    }

    public function renderHeader(TCPDF $pdf)
    {
        if (($bg = $this->getBackgroundImagePath()) && is_file($bg)) {
            // Disable auto-page-break, we use page objects instead.
            $pdf->SetAutoPageBreak(false);
            // set background image
            $pdf->Image(
                $bg,
                0,
                0,
                $this->pdfService->pageWidth,
                $this->pdfService->pageHeight
            );
            // set the starting point for the page content
            $pdf->setPageMark();
        }
    }

    public function getBackgroundImagePath(): ?string
    {
        return null;
    }

    abstract public function renderBody(TCPDF $pdf);

    public function renderFooter(TCPDF $pdf)
    {
        // To override...
    }

    #[ArrayShape([':pageNum' => 'int', ':pageTotal' => 'string'])]
    public function pageNumberArgs(TCPDF $pdf): array
    {
        return [
            ':pageNum' => $pdf->PageNo(),
            ':pageTotal' => $pdf->getAliasNbPages(),
        ];
    }

    public function renderSpan(
        TCPDF $pdf,
        array $data,
        int|float $w,
        int|float $h,
        int|float $x = null,
        int|float $y = null,
        ?int $border = null,
        int $borderColorR = 0,
        int $borderColorV = 0,
        int $borderColorB = 0
    ): float {
        if ($border) {
            $pdf->SetDrawColor($borderColorR, $borderColorV, $borderColorB);
            $pdf->Rect($x, $y, $w, $h);
            ++$x;
            ++$y;
            $w -= 2;
            $h -= 2;
            $pdf->SetDrawColor(0, 0, 0);
        }

        $attr = [];

        $attr[DomHelper::ATTRIBUTE_STYLE][DomHelper::CSS_RULE_FONT_SIZE] = $data[self::RENDER_OPTION_FONT_SIZE] ?? $this->fontSize;
        $data[self::RENDER_OPTION_LETTER_SPACING] ??= $this->letterSpacing;

        if ($data[self::RENDER_OPTION_CAPITALIZE] ?? $this->capitalize) {
            $data[self::RENDER_OPTION_CONTENT] = strtoupper($data[self::RENDER_OPTION_CONTENT]);
        }

        $data[self::RENDER_OPTION_CLASS] = ($data[self::RENDER_OPTION_CLASS] ?? '')
            .' '.($data[self::RENDER_OPTION_FONT_WEIGHT] ?? $this->fontWeight)
            .' '.($data[self::RENDER_OPTION_TEXT_ALIGN] ?? $this->textAlign);

        if (isset($data[self::RENDER_OPTION_START]) && isset($data[self::RENDER_OPTION_LENGTH])) {
            $data[self::RENDER_OPTION_CONTENT] = substr(
                $data[self::RENDER_OPTION_CONTENT],
                $data[self::RENDER_OPTION_START],
                $data[self::RENDER_OPTION_LENGTH]
            );
        }

        $data[self::RENDER_OPTION_CONTENT] = implode(
            str_repeat(
                DomHelper::CHAR_NBSP,
                $data[self::RENDER_OPTION_LETTER_SPACING]
            ),
            str_split($data[self::RENDER_OPTION_CONTENT] ?: '')
        );

        if (isset($data[self::RENDER_OPTION_NBSP]) ?? false) {
            $data[self::RENDER_OPTION_CONTENT] = str_replace(
                ' ',
                DomHelper::CHAR_NBSP,
                $data[self::RENDER_OPTION_CONTENT]
            );
        }

        $data[self::RENDER_OPTION_ATTR] = DomHelper::arrayToAttributes(($data[self::RENDER_OPTION_ATTR] ?? []) + $attr);

        return $this->renderTemplate(
            $pdf,
            '/pdf/partials/span.html.twig',
            $data,
            $w,
            $h,
            $x,
            $y
        );
    }

    public function renderTemplate(
        TCPDF $pdf,
        string $template,
        array $variables,
        float $w,
        float $h,
        float $x = null,
        float $y = null,
        int $border = 0,
        int $ln = 0,
        bool $fill = false,
        bool $reseth = true,
        string $align = '',
        bool $autopadding = true
    ): float {
        $document = $this->getPdfService();
        $html = $document->twigRender($template, $variables);

        $x ??= $document->margin;
        $y ??= $this->y;

        $border = $document->debugBorders ? 1 : $border;

        $pdf->writeHTMLCell(
            $w,
            $h,
            $x,
            $y,
            $html,
            $border,
            $ln,
            $fill,
            $reseth,
            $align,
            $autopadding
        );

        $this->y = $y + $h;

        return $this->y;
    }

    public function getPdfService(): AbstractPdfService
    {
        return $this->pdfService;
    }

    public function setPdfService(AbstractPdfService $pdfService)
    {
        $this->pdfService = $pdfService;
        $this->document = $pdfService->document;
    }

    public function footerArgs(TCPDF $pdf): array
    {
        return [];
    }

    public function renderTitle(
        TCPDF $pdf,
        string $title,
        int $num = 1,
        int|float $y = null,
        array $args = []
    ) {
        $height = 7;
        $y = null != $y ? $y : $this->y;

        $this->renderTemplateLarge(
            $pdf,
            '/pdf/partials/page-title.html.twig',
            [
                'page_title' => $this->trans($title, $args),
                'num' => $num,
            ],
            $height,
            $y
        );

        $this->y = $y + $height;
    }

    public function renderTemplateLarge(
        TCPDF $pdf,
        string $template,
        array $variables,
        int $height,
        int|float $y = null
    ): int {
        $document = $this->getPdfService();

        return $this->renderTemplate(
            $pdf,
            $template,
            $variables,
            $document->innerWidth,
            $height,
            $document->margin,
            $y
        );
    }

    public function trans(string $id, array $args = []): string
    {
        return $this->getPdfService()->translator->trans($id, $args);
    }

    public function renderSeparator(TCPDF $pdf, $marginBefore, $marginAfter)
    {
        $this->y += $marginBefore;
        $document = $this->getPdfService();
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(
            $document->margin,
            $this->y,
            $document->innerWidth + $document->margin,
            $this->y
        );
        $pdf->SetDrawColor(0, 0, 0);
        $this->y += $marginAfter;

        return $marginBefore + $marginAfter;
    }
}
