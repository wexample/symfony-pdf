<?php

namespace Wexample\SymfonyPdf\Service\Pdf;

use App\Pdf\PdfDocument;
use App\Wex\BaseBundle\Translation\Translator;
use Exception;
use Spatie\PdfToImage\Exceptions\PdfDoesNotExist;
use Spatie\PdfToImage\Pdf;
use Symfony\Component\HttpKernel\KernelInterface;
use TCPDF;
use TCPDF_FONTS;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Wexample\SymfonyHelpers\Helper\FileHelper;
use Wexample\Helpers\Helper\TextHelper;
use Wexample\SymfonyPdf\Service\Pdf\Page\Page;
use function class_exists;
use function dirname;
use function file_exists;
use function is_dir;
use function is_file;
use function mkdir;
use function pathinfo;
use function realpath;
use function scandir;
use function strlen;
use function strtolower;
use function substr;
use function unlink;

abstract class AbstractPdfService
{
    public const OUTPUT_ACTION_PRINT = 'print';

    public const OUTPUT_ACTION_DOWNLOAD = 'download';

    public const OUTPUT_ACTION_SAVE = 'save';

    public const TCPDF_ACTIONS = [
        self::OUTPUT_ACTION_PRINT => 'I',
        self::OUTPUT_ACTION_DOWNLOAD => 'D',
        self::OUTPUT_ACTION_SAVE => 'F',
    ];

    public bool $debugBorders = false;

    public ?Page $pageCurrent = null;

    public int $margin = 15;

    public int $pageWidth = 210;

    public int $pageHeight = 297;

    public float|int $innerWidth;

    public float|int $footerHeight = 35;

    public string $projectDir;

    public float|int $marginDouble;

    public float|int $marginFooter;

    public array $pages = [];

    public ?PdfDocument $document = null;

    public ?TCPDF $renderedPdf = null;

    /**
     * @throws LoaderError
     */
    public function __construct(
        KernelInterface $kernel,
        public Translator $translator,
        public Environment $twig
    ) {
        $this->marginDouble = $this->margin * 2;
        $this->marginFooter = $this->margin / 2;
        $this->innerWidth = $this->pageWidth - ($this->margin * 2);

        $this->projectDir = realpath($kernel->getProjectDir()).'/';
        $bundlePath = $kernel
                ->getBundle('WexBaseBundle')
                ->getPath().'/';

        /** @var FilesystemLoader $loader */
        $loader = $this->twig->getLoader();
        // Add bundle dir.
        $loader->addPath($this->projectDir.'templates');
        $loader->addPath($bundlePath.'Resources/templates');

        $this->useFontsDir($this->projectDir.'assets/fonts/Quicksand/');
    }

    /**
     * @throws Exception
     */
    public function useFontsDir($dir)
    {
        $scan = scandir($dir);

        foreach ($scan as $item) {
            $path = $dir.'/'.$item;
            $info = pathinfo($path);
            if (isset($info['extension']) && 'ttf' === strtolower(
                $info['extension']
            )) {
                $this->useFont($path);
            }
        }
    }

    /**
     * @throws Exception
     */
    public function useFont($ttf, $size = 96, $style = '')
    {
        if (file_exists($ttf)) {
            TCPDF_FONTS::addTTFfont(
                $ttf,
                'TrueTypeUnicode',
                $style,
                $size
            );
        } else {
            throw new Exception('Unable to find TTF font for PDF creation : '.$ttf);
        }
    }

    public function buildPage($pageClassName): ?Page
    {
        if (class_exists($pageClassName)) {
            /** @var Page $page */
            $page = new $pageClassName();
            $page->setPdfService($this);

            $this->pages[] = $page;

            return $page;
        }

        return null;
    }

    public function render(
        string $action = self::OUTPUT_ACTION_PRINT
    ): string {
        $pdf = $this->renderedPdf ?? $this->renderPdf();

        $file = $this->addFileExtension(
            $this->getDownloadFileName()
        );

        $pdf->Output(
            $file,
            self::TCPDF_ACTIONS[$action]
        );

        return $file;
    }

    public function renderPdf(): TCPDF
    {
        // Build full length translation file.
        $fullClassDomain = $this->translator->setDomainFromClass(
            Translator::DOMAIN_TYPE_PDF,
            $this,
            'App\\Service\\'
        );

        // But use file without useless suffix.
        $this->translator->setDomain(
            Translator::DOMAIN_TYPE_PDF,
            substr($fullClassDomain, 0, -strlen('_pdf_service'))
        );

        $pdf = new TCPDF();
        $pdf->SetTitle($this->getTitle());
        $pdf->SetSubject($this->getTitle());

        // Set invoice information.
        $pdf->SetCreator($this->getPdfCreator());
        $pdf->SetAuthor($this->getPdfAuthor());

        // set image scale factor.
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // set default font subsetting mode
        $pdf->setFontSubsetting(true);

        // set auto page breaks
        $pdf->SetAutoPageBreak(false);

        /** @var Page $page */
        foreach ($this->pages as $page) {
            $this->pageCurrent = $page;
            $page->render($pdf);
        }

        $this->translator->revertDomain(
            Translator::DOMAIN_TYPE_PDF
        );

        $this->renderedPdf = $pdf;

        return $pdf;
    }

    abstract public function getTitle(): string;

    abstract public function getPdfCreator(): string;

    abstract public function getPdfAuthor(): string;

    public function addFileExtension($file): string
    {
        return $file.'.pdf';
    }

    public function getDownloadFileName(): string
    {
        return $this->getFileName();
    }

    abstract public function getFileName(): string;

    public function twigRender($template, $variables = []): string
    {
        $variables['document'] = $this;
        $variables['page'] = $this->pageCurrent;
        $variables['projectDir'] = $this->projectDir;
        $variables['content'] ??= '';

        return $this->twig->render(
            $template,
            $variables
        );
    }

    public function renderToDir(): string
    {
        $this->pdfDeleteFileAndPreview();

        $dir = $this->generateDir();

        // Create directory.
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileName = $this->generateFileName();

        $pdf = $this->renderedPdf ?? $this->renderPdf();

        $pdf->Output(
            $dir.$fileName,
            self::TCPDF_ACTIONS[self::OUTPUT_ACTION_SAVE]
        );

        return $dir.$fileName;
    }

    public function pdfDeleteFileAndPreview()
    {
        if ($this->hasExistingPdf()) {
            $this->pdfDeletePreview();
            $this->pdfDelete();
        }
    }

    public function hasExistingPdf(): bool
    {
        // Delete pdf.
        $pdfAbsolutePath = $this->getFileAbsolutePath();

        return is_file($pdfAbsolutePath);
    }

    public function getFileAbsolutePath(): string
    {
        return $this->generateDir().$this
                ->getFileName();
    }

    abstract public function generateDir(): string;

    public function pdfDeletePreview()
    {
        // Delete preview.
        $previewAbsolutePath = $this->generatePreviewAbsolutePath();

        if (is_file($previewAbsolutePath)) {
            unlink($previewAbsolutePath);
        }
    }

    public function generatePreviewAbsolutePath(): string
    {
        return $this->generatePreviewDir()
            // Turn pdf to jpeg extension.
            .$this->generatePreviewFileName();
    }

    public function generatePreviewDir(): ?string
    {
        return null;
    }

    public function generatePreviewFileName(): string
    {
        return pathinfo(
            $this->getFileName()
        )['filename'].'.jpg';
    }

    public function pdfDelete()
    {
        if ($this->hasExistingPdf()) {
            // Delete pdf.
            $pdfAbsolutePath = $this->getFileAbsolutePath();

            unlink($pdfAbsolutePath);
        }
    }

    public function generateFileName(): string
    {
        return TextHelper::uniqueFileNameInDir(
            $this->generateDir(),
            FileHelper::FILE_EXTENSION_PDF
        );
    }

    public function getPageCurrent(): Page
    {
        return $this->pageCurrent;
    }

    public function setPageCurrent(
        Page $pageCurrent
    ): void {
        $this->pageCurrent = $pageCurrent;
    }

    abstract public function hasPdfPreviewPath(): bool;

    /**
     * @throws PdfDoesNotExist
     */
    public function createAndGetPdfPreviewPath(): ?string
    {
        $pdfPreviewAbsolutePath = $this->generatePreviewAbsolutePath();

        // Image file does not exist.
        if (!is_file($pdfPreviewAbsolutePath)) {
            $pdfAbsolutePath = $this->getFileAbsolutePath();

            if (!is_file($pdfAbsolutePath)) {
                // No pdf.
                return null;
            }

            $pdfPreviewAbsoluteDir = dirname($pdfPreviewAbsolutePath);

            if (!is_dir($pdfPreviewAbsoluteDir)) {
                mkdir($pdfPreviewAbsoluteDir, 0777, true);
            }

            $pdf = new Pdf($pdfAbsolutePath);
            $pdf->saveImage($pdfPreviewAbsolutePath);
        }

        return $pdfPreviewAbsolutePath;
    }
}
