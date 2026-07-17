<?php

class EpubPackager
{
    private array $stories;
    private string $scanFolder;
    private string $workFolder;
    private string $outputFolder;
    private string $language;
    private string $projectRoot;

    public function __construct(Config $config, string $projectRoot)
    {
        $this->scanFolder   = $config->scanFolder;
        $this->workFolder   = $config->workFolder;
        $this->outputFolder = $config->outputFolder;
        $this->language     = $config->language;
        $this->projectRoot  = $projectRoot;

        if (!is_dir($this->scanFolder) || !is_readable($this->scanFolder)) {
            throw new RuntimeException("Scan folder not found or not readable: {$this->scanFolder}");
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required (ZipArchive not found).');
        }

        if (!is_dir($this->workFolder)) {
            mkdir($this->workFolder, 0777, true);
        }

        if (!is_dir($this->outputFolder)) {
            mkdir($this->outputFolder, 0777, true);
        }

        $this->stories = scandir($this->scanFolder);
    }

    public function run()
    {
        echo 'Running packager...' . "\n";

        // Create an array to hold stories grouped by series
        $series = $this->groupStoriesBySeries($this->stories);

        if (empty($series)) {
            echo "No story files matched the expected naming pattern in {$this->scanFolder}.\n";
            return;
        }

        $this->buildPackages($series);
    }

    // Begin building the package
    private function buildPackages($series)
    {
        foreach ($series as $name => $txtFiles) {

            // Get first few lines of the story and show them
            // Then prompt the user for name and description
            $storyFile = $this->scanFolder . '/' . $txtFiles[0];
            $storyLines = file($storyFile);
            $storyLines = array_slice($storyLines, 0, 60); // Get first 60 lines
            echo "First few lines of $txtFiles[0]:\n";
            foreach ($storyLines as $line) {
                echo $line;
            }
            echo "\n\n";

            // Try to detect a title from the text itself, and let it prefill the prompt
            $detectedTitle = $this->detectTitle($storyLines);

            $titlePrompt = $detectedTitle !== null
                ? "Enter name for series '$name' [$detectedTitle]: "
                : "Enter name for series '$name': ";

            $titleInput = Prompt::ask($titlePrompt);
            $title = $titleInput !== '' ? $titleInput : ($detectedTitle ?? $name);

            // Try to detect a description between the first two "***" markers, and any
            // (comma, separated, tags) living inside it
            $detectedDescription = $this->detectDescription($storyLines);
            $tags = [];

            if ($detectedDescription !== null) {
                $tags = $this->extractTags($detectedDescription);

                echo "Detected description for '$title':\n$detectedDescription\n";
                if (!empty($tags)) {
                    echo 'Detected tags: ' . implode(', ', $tags) . "\n";
                }

                $useDetected = strtolower(Prompt::ask('Use this description? [Y/n]: '));

                if ($useDetected === '' || $useDetected === 'y') {
                    $description = $detectedDescription . "\n";
                } else {
                    $description = Prompt::askMultiline("Enter description for series '$title' (end with an empty line):");
                    $tags = $this->promptForTags();
                }
            } else {
                $description = Prompt::askMultiline("Enter description for series '$title' (end with an empty line):");
                $tags = $this->promptForTags();
            }

            $uuid = $this->generateUUID();

            echo "Building package for series '$title'...\n";
            echo "Description: $description\n";

            $this->buildPackage($name, $title, $description, $tags, $uuid, $txtFiles);

        }

    }

    // Look for a "Title:"-style label in the opening lines to suggest as a prefill.
    // Story text files aren't consistently formatted, so this is a best-effort guess only.
    private function detectTitle(array $lines): ?string
    {
        $pattern = '/^\s*(?:story\s+|book\s+)?title\s*[:\-]\s*(.+?)\s*$/i';

        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches) && $matches[1] !== '') {
                return $matches[1];
            }
        }

        // Some files have no "Title:" label at all — instead the title sits on its own
        // line right after a "----" style separator (often following a copyright/
        // attribution notice at the top of the file)
        foreach ($lines as $index => $line) {
            if (preg_match('/^-{3,}\s*$/', trim($line))) {
                for ($i = $index + 1; $i < count($lines); $i++) {
                    $candidate = trim($lines[$i]);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    // Look for a description sitting between the first two "***" markers in the opening lines.
    // Story text files aren't consistently formatted, so this is a best-effort guess only.
    private function detectDescription(array $lines): ?string
    {
        $text = implode('', $lines);
        $parts = explode('***', $text, 3);

        if (count($parts) < 3) {
            return null;
        }

        $description = trim($parts[1]);

        return $description !== '' ? $description : null;
    }

    // Pull a (comma, separated, tag list) out of a parenthesised group in the description,
    // and strip it from the description text since it's now recorded separately.
    private function extractTags(string &$description): array
    {
        if (!preg_match('/\(([^()]+)\)/', $description, $matches)) {
            return [];
        }

        $tags = array_map('trim', explode(',', $matches[1]));
        $tags = array_filter($tags, fn($tag) => $tag !== '');

        $description = trim(str_replace($matches[0], '', $description));

        return array_values($tags);
    }

    // Ask for tags manually when none could be detected from the text
    private function promptForTags(): array
    {
        $input = Prompt::ask('Enter tags (comma separated), or leave blank: ');

        if ($input === '') {
            return [];
        }

        $tags = array_map('trim', explode(',', $input));

        return array_values(array_filter($tags, fn($tag) => $tag !== ''));
    }

    private function buildPackage($name, $title, $description, $tags, $uuid, $files)
    {

        // Create a fresh directory for the package, clearing out any leftovers from a previous run
        $packageDir = $this->workFolder . '/' . $name;
        if (is_dir($packageDir)) {
            $this->deleteDirectory($packageDir);
        }
        mkdir($packageDir, 0777, true);

        $this->createMimetype($packageDir);
        $this->createMetaInf($packageDir);
        $this->createStylesheet($packageDir);
        $this->copyThumbnail($packageDir);

        $htmlfiles = $this->exportTextToHTML($packageDir, $title, $description, $files);
        $this->createTOC($packageDir, $title, $description, $uuid, $htmlfiles);
        $this->createOPF($packageDir, $title, $description, $tags, $uuid, $htmlfiles);

        $this->createEPUB($packageDir, $title);

    }

    // Recursively delete a directory and its contents
    private function deleteDirectory(string $dir)
    {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    // Export text files to HTML format
    private function exportTextToHTML($packageDir, $title, $description, $textFiles)
    {
        $htmlFiles = [];

        $htmlCount = 0;

        foreach ($textFiles as $file) {

            $content = file_get_contents($this->scanFolder . '/' . $file);

            // check for chapters in text
            $chapterPattern = '/(Chapter \d+)/i';

            // Split the text based on the chapters found
            $chapters = preg_split($chapterPattern, $content);

            // make sure the first part is not empty
            if (empty($chapters[0])) {
                array_shift($chapters);
            }

            echo count($chapters) . " chapters found in $file\n";
            // Create a new HTML file for each chapter
            if ( count($chapters) > 2 ) {

                foreach ($chapters as $index => $chapter) {
                    // Create a new HTML file for each chapter
                    $htmlCount++;

                    $htmlFile = $packageDir . '/index_html_' . $htmlCount . '.html';
                    $htmlFiles[] = $htmlFile;

                    $htmlContent = '<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
    <head>
        <title>' . $title . '</title>
        <link rel="stylesheet" type="text/css" href="style.css"/>
    </head>
    <body>
        <div class="content">' . $this->wrapTextInParagraphs($chapter) . '</div>
    </body>
</html>';

                    file_put_contents($htmlFile, $htmlContent);

                }

            } else {

                // Create a new HTML file for the entire text
                $htmlCount++;

                $htmlFile = $packageDir . '/index_html_' . $htmlCount . '.html';
                $htmlFiles[] = $htmlFile;

                $htmlContent = '<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">
    <head>
        <title>' . $title . '</title>
        <link rel="stylesheet" type="text/css" href="style.css"/>
    </head>
    <body>
        <div class="content">' . $this->wrapTextInParagraphs($content) . '</div>
    </body>
</html>';

                file_put_contents($htmlFile, $htmlContent);
            }

        }

        return $htmlFiles;
    }

    private function wrapTextInParagraphs($text)
    {

        // Clean up the text
        $text = str_replace('_________________________________________', "", $text); // Convert \r to \n

        // Check if the text contains the *** pattern
        if (strpos($text, '***') !== false) {
            // Split the text at the first occurrence of ***
            $splitText = explode("***", $text, 3);

            $beforeParagraphs = '';

            // Process the part before the first set of ***
            // Apply nl2br only before ***
            if (isset($splitText[0])) {
                $beforeParagraphs = nl2br(htmlspecialchars($splitText[0])); // Convert newlines to <br> tags
            }

            // Process the part after the first set of ***
            $afterParagraphs = '';
            if (isset($splitText[1])) {
                // Wrap paragraphs in <p> tags after ***
                $paragraphs = preg_split('/\n\n+/', $splitText[1]);
                foreach ($paragraphs as $paragraph) {
                    $paragraph = str_replace("\n", ' ', $paragraph); // Remove internal line breaks
                    $afterParagraphs .= "<p>" . htmlspecialchars($paragraph) . "</p>\n\n";
                }
            }

            // If there's a third part after the second ***
            if (isset($splitText[2])) {
                // Wrap paragraphs in <p> tags after the second set of ***
                $paragraphs = preg_split('/\n\n+/', $splitText[2]);
                foreach ($paragraphs as $paragraph) {
                    $paragraph = str_replace("\n", ' ', $paragraph); // Remove internal line breaks
                    $afterParagraphs .= "<p>" . htmlspecialchars($paragraph) . "</p>\n\n";
                }
            }

            // Return the combined result (before *** part with <br>, and after wrapped in <p>)
            return $beforeParagraphs . "<hr>" . $afterParagraphs;
        } else {
            // If no *** pattern is found, wrap all the text in <p> tags
            $paragraphs = preg_split('/\n\n+/', $text);
            $wrappedText = '';
            foreach ($paragraphs as $paragraph) {
                $paragraph = str_replace("\n", ' ', $paragraph); // Remove internal line breaks
                $wrappedText .= "<p>" . htmlspecialchars($paragraph) . "</p>\n\n";
            }
            return $wrappedText;
        }
    }


    // crate the OPF file
    private function createOPF($packageDir, $title, $description, $tags, $uuid, $htmlFiles)
    {

        // remove empty line from the description
        $description = trim($description);
        $description = preg_replace('/\s+/', ' ', $description); // Remove extra spaces

        $opfContent = '<?xml version="1.0" encoding="utf-8"?>
    <package version="2.0" unique-identifier="uuid_id" xmlns="http://www.idpf.org/2007/opf">
        <metadata xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:calibre="http://calibre.kovidgoyal.net/2009/metadata">
        <dc:title>' . htmlspecialchars($title) . '</dc:title>
        <dc:description>' . htmlspecialchars($description) . '</dc:description>
        <dc:identifier id="BookId">' . $uuid . '</dc:identifier>
        <dc:language>' . htmlspecialchars($this->language) . '</dc:language>
';

        // Tags are represented in the OPF as dc:subject entries — this is what Calibre and
        // most readers display as "Tags"
        foreach ($tags as $tag) {
            $opfContent .= '        <dc:subject>' . htmlspecialchars($tag) . '</dc:subject>' . "\n";
        }

        $opfContent .= '        <meta name="cover" content="thumbnail.jpg"/>
    </metadata>
    <manifest>
';

        foreach ($htmlFiles as $index => $file) {
            $opfContent .= '        <item id="item' . ($index + 1) . '" href="' . basename($file) . '" media-type="application/xhtml+xml"/>'. "\n";
        }

        // Add the CSS file to the manifest
        $opfContent .= '        <item id="style" href="style.css" media-type="text/css"/>' . "\n"; ;
        $opfContent .= '        <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>' . "\n"; ;
        $opfContent .= '        <item id="thumbnail" href="thumbnail.jpg" media-type="image/jpeg"/>' . "\n"; ;

        $opfContent .= '    </manifest>
    <spine toc="ncx">
';

        foreach ($htmlFiles as $index => $file) {
            $opfContent .= '        <itemref idref="item' . ($index + 1) . '"/>' . "\n";
        }

        $opfContent .= '    </spine>
</package>';

        file_put_contents($packageDir . '/content.opf', $opfContent);
    }

    // Create the EPUB file
    private function createEPUB($packageDir, $title)
    {
        $zip = new ZipArchive();
        $epubFile = $this->outputFolder . '/' . $title . '.epub';

        if ($zip->open($epubFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $zip->addFile($packageDir . '/mimetype', 'mimetype');
            $zip->addEmptyDir('META-INF');
            $zip->addFile($packageDir . '/META-INF/container.xml', 'META-INF/container.xml');

            // Add all HTML files
            foreach (glob($packageDir . '/*.html') as $file) {
                $zip->addFile($file, basename($file));
            }

            // Add the CSS file
            $zip->addFile($packageDir . '/style.css', 'style.css');

            // Add the OPF file
            $zip->addFile($packageDir . '/content.opf', 'content.opf');

            // Add the thumbnail image
            $zip->addFile($packageDir . '/thumbnail.jpg', 'thumbnail.jpg');

            // Add the NCX file
            $zip->addFile($packageDir . '/toc.ncx', 'toc.ncx');

            $zip->close();
            echo "EPUB file created: " . $epubFile . "\n";
        } else {
            echo "Failed to create EPUB file.\n";
        }
    }

    // create TOC file
    private function createTOC($packageDir, $title, $description, $uuid, $htmlFiles)
    {
        $tocContent = '<?xml version="1.0" encoding="utf-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="en-US">
  <head>
    <meta name="dtb:uid" content="'.$uuid.'"/>
    <meta name="dtb:depth" content="2"/>
    <meta name="dtb:generator" content="calibre (7.13.0)"/>
    <meta name="dtb:totalPageCount" content="0"/>
    <meta name="dtb:maxPageNumber" content="0"/>
  </head>
  <docTitle>
    <text>' . htmlspecialchars($title) . '</text>
  </docTitle>
  <navMap>
';

        $count = 0;

        foreach ($htmlFiles as $index => $file) {
            $count++;
            $tocContent .= '    <navPoint id="navPoint-' . ($index + 1) . '" playOrder="' . ($index + 1) . '">
      <navLabel>
        <text>Chapter ' . $count . '</text>
      </navLabel>
      <content src="' . basename($file) . '"/>
    </navPoint>' . "\n";
        }

        $tocContent .= '  </navMap>
</ncx>';
        file_put_contents($packageDir . '/toc.ncx', $tocContent);
    }

    // Create the mimetype file
    private function createMimetype($packageDir) {

        $mimetypeFile = $packageDir . '/mimetype';
        if (!file_exists($mimetypeFile)) {
            file_put_contents($mimetypeFile, 'application/epub+zip');
            echo "Created mimetype file.\n";
        } else {
            echo "Mimetype file already exists.\n";
        }

    }

    // Create the META-INF directory and container.xml file
    private function createMetaInf($packageDir) {

        $metaInfDir = $packageDir . '/META-INF';
        if (!is_dir($metaInfDir)) {
            mkdir($metaInfDir, 0777, true);
        }

        $containerXML = '<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
    <rootfiles>
        <rootfile full-path="content.opf" media-type="application/oebps-package+xml"/>
    </rootfiles>
</container>';

        $containerFile = $metaInfDir . '/container.xml';
        if (!file_exists($containerFile)) {
            file_put_contents($containerFile, $containerXML);
            echo "Created META-INF/container.xml file.\n";
        } else {
            echo "META-INF/container.xml file already exists.\n";
        }
    }

    // Copy thumbnail image
    private function copyThumbnail($packageDir) {
        $thumbnailSrc = $this->projectRoot . '/thumbnail.jpg';
        $thumbnailDest = $packageDir . '/thumbnail.jpg';

        if (file_exists($thumbnailSrc)) {
            copy($thumbnailSrc, $thumbnailDest);
            echo "Thumbnail image copied.\n";
        } else {
            echo "Thumbnail image not found.\n";
        }
    }

    // Create stylesheet
    private function createStylesheet($packageDir) {

        $cssFile = $packageDir . '/style.css';
        if (!file_exists($cssFile)) {
            $cssContent = 'body { font-family: Arial, sans-serif; }';
            file_put_contents($cssFile, $cssContent);
            echo "Created stylesheet file.\n";
        } else {
            echo "Stylesheet file already exists.\n";
        }
    }

    private function generateUUID()
    {
        $data = random_bytes(16);

        // Set the version (4) and variant (10)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set variant to 10

        // Format the UUID
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        return $uuid;
    }

    // Group the files by series based on the pattern in the filenames
    private function groupStoriesBySeries($stories)
    {
        $grouped = [];

        foreach ($stories as $story) {

            // Skip '.' and '..' directories
            if ($story == '.' || $story == '..') {
                continue;
            }

            // First regex: Matches filenames like "amber1.txt", "amanda4.txt"
            if (preg_match('/^([a-zA-Z]+)(\d+)\.txt$/i', $story, $matches)) {
                $seriesName = $matches[1];  // Series name (e.g., "amber", "amanda")

                if (!isset($grouped[$seriesName])) {
                    $grouped[$seriesName] = [];
                }

                $grouped[$seriesName][] = $story;
            }
            // Second regex: Matches filenames like "amanda-4.txt", "amber-ch1.txt"
            else if (preg_match('/^([a-zA-Z]+)(?:[-_](ch\d+|part\d+|\d+))?\.txt$/i', $story, $matches)) {
                $seriesName = $matches[1];  // Series name (e.g., "amber", "amanda")

                if (!isset($grouped[$seriesName])) {
                    $grouped[$seriesName] = [];
                }

                $grouped[$seriesName][] = $story;
            }
        }

        // Sort files within each series naturally, so e.g. "amber2.txt" comes before "amber10.txt"
        foreach ($grouped as $seriesName => $files) {
            natcasesort($files);
            $grouped[$seriesName] = array_values($files);
        }

        return $grouped;
    }

}
