<?php
$file = 'C:\\Users\\LESTA\\Downloads\\Forums_specs.docx';
$zip = new ZipArchive();
if ($zip->open($file) === TRUE) {
    if (($index = $zip->locateName('word/document.xml')) !== false) {
        $content = $zip->getFromIndex($index);
        $zip->close();
        
        $content = str_replace('</w:p>', "\n\n", $content);
        $content = preg_replace('/<[^>]+>/', '', $content);
        
        echo $content;
    } else {
        echo "No 'word/document.xml' found\n";
    }
} else {
    echo "NO SE PUDO ABRIR: $file";
}
