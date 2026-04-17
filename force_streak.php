<?php
require 'config/database.php';

// Inyectar 1 de Racha a TODOS los usuarios para simular su "Primer Día" en el nuevo sistema
$pdo->exec("UPDATE User SET streakCount = 1 WHERE streakCount = 0 OR streakCount IS NULL");

echo "<h3 style='color:green;'>¡Todos los estudiantes ahora tienen 1 Día de Racha!</h3>";
echo "<p>Vuelve a ejecutar <b>/fix_achievements.php</b> para que el motor les otorgue su Medalla de 'Primer Paso'.</p>";
