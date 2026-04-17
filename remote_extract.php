<?php
$zip = new ZipArchive;
$res = $zip->open('Hubeurosoft_Update_V3.zip');
if ($res === TRUE) {
    if ($zip->extractTo(__DIR__)) {
        $zip->close();
        echo "¡Actualización V3 desplegada exitosamente!\n";
        unlink('Hubeurosoft_Update_V3.zip');
        unlink(__FILE__);
    } else {
        echo "Error: No se pudo extraer (posible falta de permisos).\n";
    }
} else {
    echo "Error: Archivo ZIP no válido o no encontrado.\n";
}
?>
