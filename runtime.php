<?php

$EPUB_Packer = new EPUB_Packager();
$EPUB_Packer->run();

class EPUB_Packager
{
   
    private $stories;

    public function __construct()
    {
        $this->stories      = scandir(dirname(__FILE__) . '/stories/');

    }

    public function run() 
    {
        echo 'Running packager...'. "\n";

        // Create an array to hold stories grouped by series
        $series = $this->groupStoriesBySeries($this->stories);

        

        // var_dump($series);

        $this->buildPackages($series);

    }

    // Beging building the package
    private function buildPackages($series)
    {
        
        foreach ($series as $name => $txtFiles) {
            
            // Get first few lines of the story and show them
            // Then prompt the user for name and description
            $storyFile = dirname(__FILE__) . '/stories/' . $txtFiles[0];
            $storyLines = file($storyFile);
            $storyLines = array_slice($storyLines, 0, 60); // Get first 5 lines
            echo "First few lines of $txtFiles[0]:\n";
            foreach ($storyLines as $line) {
                echo $line;
            }
            echo "\n\n";

            // Get the name for the series
            $title = readline("Enter name for series '$name': ");

            // Collect multiline description
            echo "Enter description for series '$title' (end with an empty line):\n";
            $description = '';
            while (true) {
                $line = readline();  // Read a line of input
                if (empty($line)) {   // If the line is empty, break the loop
                    break;
                }
                $description .= $line . "\n";  // Append the line to the description
            }

            $uuid = $this->generateUUID();

            echo "Building package for series '$title'...\n";
            echo "Description: $description\n";

            $this->buildPackage($name, $title, $description, $uuid, $txtFiles);

        }

    }

    private function buildPackage($name, $title, $description, $uuid, $files)
    {

        // Create a directory for the package
        $packageDir = dirname(__FILE__) . '/temp/' . $name;
        if (!is_dir($packageDir)) {
            mkdir($packageDir, 0777, true);
        }
        
        $this->createMimetype($packageDir);
        $this->createMetaInf($packageDir);
        $this->createStylesheet($packageDir);
        $this->copyThumbnail($packageDir);

        $htmlfiles = $this->exportTextToHTML($packageDir, $title, $description, $files);
        $this->createTOC($packageDir, $title, $description, $uuid, $htmlfiles);
        $this->createOPF($packageDir, $title, $description, $uuid, $htmlfiles);

        $this->createEPUB($packageDir, $title);

    }

    // Export text files to HTML format
    private function exportTextToHTML($packageDir, $title, $description, $textFiles)
    {
        $htmlFiles = [];

        $htmlCount = 000;

        foreach ($textFiles as $file) {

            $content = file_get_contents(dirname(__FILE__) . '/stories/' . $file);

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
                    // $htmlFile = $packageDir . '/' . str_pad($htmlCount, 3, '0', STR_PAD_LEFT) . '.html';
                    // $htmlFiles[] = $htmlFile;

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
                // $htmlFile = $packageDir . '/' . str_pad($htmlCount, 3, '0', STR_PAD_LEFT) . '.html';
                // $htmlFiles[] = $htmlFile;

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
    private function createOPF($packageDir, $title, $description, $uuid, $htmlFiles)
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
        <dc:language>en</dc:language>
        <meta name="cover" content="thumbnail.jpg"/>
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
    <spine toc="ncx">>
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
        $epubFile = dirname(__FILE__) . '/temp/' . $title . '.epub';

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
        $thumbnailSrc = dirname(__FILE__) . '/thumbnail.jpg';
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

            // echo 'Processing: ' . $story . "\n";

            // First regex: Matches filenames like "amber1.txt", "amanda4.txt"
            if (preg_match('/^([a-zA-Z]+)(\d+)\.txt$/i', $story, $matches)) {
                // var_dump($matches);  // For debugging

                $seriesName = $matches[1];  // Series name (e.g., "amber", "amanda")
                $part = $matches[2];        // The number (e.g., "1", "4")

                // If the series doesn't exist in the array, initialize it
                if (!isset($grouped[$seriesName])) {
                    $grouped[$seriesName] = [];
                }

                // Add the file to the series group
                $grouped[$seriesName][] = $story;
            }
            // Second regex: Matches filenames like "amanda-4.txt", "amber-ch1.txt"
            else if (preg_match('/^([a-zA-Z]+)(?:[-_](ch\d+|part\d+|\d+))?\.txt$/i', $story, $matches)) {
                // var_dump($matches);  // For debugging

                $seriesName = $matches[1];  // Series name (e.g., "amber", "amanda")
                $part = isset($matches[2]) ? $matches[2] : null; // Chapter/part info (e.g., "ch1", "part2")

                // If the series doesn't exist in the array, initialize it
                if (!isset($grouped[$seriesName])) {
                    $grouped[$seriesName] = [];
                }

                // Add the file to the series group
                $grouped[$seriesName][] = $story;
            } else {
                // echo "No match for: $story\n";  // Debugging output
            }
        }

        // var_dump($grouped);  // For debugging

        return $grouped;
    }


}
    