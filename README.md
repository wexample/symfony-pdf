# symfony_pdf

Version: 1.0.97

`wexample/symfony-pdf` is a Symfony bundle that wraps TCPDF to let backend developers generate structured PDF documents from Twig templates. It provides `AbstractPdfService` as a base to extend for each document type — covering layout dimensions, font loading, page assembly, and output as inline stream, download, or saved file — alongside abstract `Page` and `Items` classes that split rendering into header, body, and footer lifecycle methods with automatic multi-page pagination for item lists.

## Table of Contents

- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

The bundle has three layers: a Symfony integration layer that registers services into the container, a service layer (`AbstractPdfService`) that owns the TCPDF instance and document lifecycle, and a page layer (`Page` / `Items`) that owns per-page layout and rendering.

### Symfony integration

src/WexampleSymfonyPdfBundle.php extends `AbstractBundle` from `wexample/symfony-helpers`, which provides the standard bundle wiring.

src/DependencyInjection/WexampleSymfonyPdfExtension.php extends `AbstractWexampleSymfonyExtension` and implements `load()` with a single call to `$this->loadConfig(__DIR__, $container)`, which reads src/Resources/config/services.yaml.

That file registers everything under `Wexample\SymfonyPdf\Service\` with `autowire: true`, `autoconfigure: true`, and the `controller.service_arguments` tag, so any concrete service class placed under `src/Service/` is automatically available for injection.

### AbstractPdfService

src/Service/Pdf/AbstractPdfService.php is the document root. Application code extends it once per document type and implements the required abstract methods:

| Abstract method | Responsibility |
|---|---|
| `getTitle()` | PDF title and subject metadata |
| `getPdfCreator()` / `getPdfAuthor()` | PDF creator/author metadata |
| `getFileName()` | base file name (without extension) |
| `generateDir()` | absolute directory for saved files |
| `hasPdfPreviewPath()` | whether the document supports preview images |

The constructor receives `KernelInterface`, `Translator`, and Twig `Environment`. It computes the derived layout constants (`innerWidth`, `marginDouble`, `marginFooter`), registers the project's `templates/` directory and the base bundle's templates with the Twig filesystem loader, and loads every `.ttf` file it finds under `assets/fonts/Quicksand/` into TCPDF via `TCPDF_FONTS::addTTFfont()`. Additional font directories can be loaded with `useFontsDir($dir)`.

Pages are registered by calling `buildPage(string $pageClassName)`, which constructs the given class, calls `setPdfService($this)` on it, and appends it to `$this->pages`.

Output is controlled by three constants:

```php
AbstractPdfService::OUTPUT_ACTION_PRINT    // streams inline to the browser ('I')
AbstractPdfService::OUTPUT_ACTION_DOWNLOAD // forces a download ('D')
AbstractPdfService::OUTPUT_ACTION_SAVE     // writes to disk ('F')
```

`render(string $action)` calls `renderPdf()` (or reuses a cached result), then passes the TCPDF action letter to `$pdf->Output()`. `renderToDir()` always writes to disk, creating the target directory if absent, and returns the absolute file path.

`twigRender(string $template, array $variables)` is the bridge used by the page layer: it merges `document`, `page`, and `projectDir` into the variable map and calls `$this->twig->render()`, returning the HTML string.

Preview images are generated from saved PDFs via `spatie/pdf-to-image` in `createAndGetPdfPreviewPath()`, which lazily converts the first page of the PDF to a `.jpg` alongside the PDF file.

### Page layer

#### Page

src/Service/Pdf/Page/Page.php is the abstract base for every PDF page. The rendering lifecycle is:

```
Page::render(TCPDF $pdf)
  └─ pdf->AddPage()
  └─ renderHeader($pdf)   — optional: paints a background image at (0, 0)
  └─ renderBody($pdf)     — abstract, must be implemented
  └─ renderFooter($pdf)   — optional hook, default is a no-op
```

The class tracks vertical position in `$this->y` and advances it after every cell is written. The helpers that place content all follow the same flow: build an HTML string via `AbstractPdfService::twigRender()`, then write it with `TCPDF::writeHTMLCell()`.

Key helpers:

- **`renderTemplate()`** — places a Twig template at an explicit `(x, y, w, h)` position; advances `$this->y` by `$h`.
- **`renderTemplateLarge()`** — shorthand that sets `w = $document->innerWidth` and `x = $document->margin`.
- **`renderSpan()`** — renders the partial `pdf/partials/span.html.twig` with typography options (font size, weight, letter spacing, capitalization, text alignment, optional border rectangle).
- **`renderTitle()`** — renders `pdf/partials/page-title.html.twig` at full inner width.
- **`renderSeparator()`** — draws a grey horizontal line at the current `$this->y`.

`setPdfService(AbstractPdfService $service)` links the page to its owning service, and also copies `$service->document` into `$this->document` for convenient access in subclasses.

#### Items

src/Service/Pdf/Page/Items.php extends `Page` and implements automatic multi-page pagination for lists. Subclasses implement four additional abstract methods:

| Method | Responsibility |
|---|---|
| `getItems()` | returns the full ordered list to render |
| `renderListHeader(TCPDF $pdf)` | renders the list header row |
| `renderItem(TCPDF $pdf, $item, float $y)` | renders one row at position `$y` |
| `renderTotalBlock(TCPDF $pdf)` | renders the totals block at the end of the last page |

`renderBody()` computes how many items fit in the available vertical area (`floor($areaHeight / $this->itemHeight)`). Items beyond that limit are sliced off and a new instance of `$this->listClass` is constructed with the remaining items, which recursively calls `render()` on the TCPDF object, producing a new physical page. If the list ends but there is not enough room for the totals block, an empty continuation page is spawned to hold it.

### Call path through the layers

```
Application code
  └─ MyPdfService::buildPage(MyPage::class)   // registers a Page
  └─ MyPdfService::render('print')
       └─ renderPdf()
            └─ new TCPDF(), set metadata
            └─ foreach $this->pages as $page
                 └─ $this->pageCurrent = $page
                 └─ Page::render($pdf)
                      └─ pdf->AddPage()
                      └─ renderHeader($pdf)
                      └─ renderBody($pdf)
                           └─ renderTemplate() / renderSpan() / …
                                └─ AbstractPdfService::twigRender($template, $vars)
                                     └─ Twig::render($template, $vars)  → HTML string
                                └─ TCPDF::writeHTMLCell(…, $html, …)
                      └─ renderFooter($pdf)
       └─ pdf->Output($file, 'I')
```

The service layer controls the document, owns the TCPDF instance, and provides the Twig bridge. The page layer owns all coordinate arithmetic and decides which templates are written where. Neither layer knows about the other's internal structure: they communicate only through `setPdfService()` / `getPdfService()` and the shared `TCPDF` handle passed to each `render()` call.

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- wexample/symfony-helpers: >=7.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
